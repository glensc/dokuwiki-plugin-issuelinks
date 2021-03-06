<?php

namespace dokuwiki\plugin\issuelinks\services;

use dokuwiki\Form\Form;
use dokuwiki\plugin\issuelinks\classes\HTTPRequestException;
use dokuwiki\plugin\issuelinks\classes\Issue;
use dokuwiki\plugin\issuelinks\classes\Repository;
use dokuwiki\plugin\issuelinks\classes\RequestResult;

class GitLab extends AbstractService
{

    const SYNTAX = 'gl';
    const DISPLAY_NAME = 'GitLab';
    const ID = 'gitlab';

    protected $dokuHTTPClient;
    protected $gitlabUrl;
    protected $token;
    protected $configError;
    protected $user;
    protected $total;

    protected function __construct()
    {
        $this->dokuHTTPClient = new \DokuHTTPClient();
        /** @var \helper_plugin_issuelinks_db $db */
        $db = plugin_load('helper', 'issuelinks_db');
        $gitLabUrl = $db->getKeyValue('gitlab_url');
        $this->gitlabUrl = $gitLabUrl ? trim($gitLabUrl, '/') : null;
        $authToken = $db->getKeyValue('gitlab_token');
        $this->token = $authToken;
    }

    /**
     * Decide whether the provided issue is valid
     *
     * @param Issue $issue
     *
     * @return bool
     */
    public static function isIssueValid(Issue $issue)
    {
        $summary = $issue->getSummary();
        $valid = !blank($summary);
        $status = $issue->getStatus();
        $valid &= !blank($status);
        return $valid;
    }

    /**
     * Provide the character separation the project name from the issue number, may be different for merge requests
     *
     * @param bool $isMergeRequest
     *
     * @return string
     */
    public static function getProjectIssueSeparator($isMergeRequest)
    {
        return $isMergeRequest ? '!' : '#';
    }

    public static function isOurWebhook()
    {
        global $INPUT;
        if ($INPUT->server->has('HTTP_X_GITLAB_TOKEN')) {
            return true;
        }

        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        if (null === $this->gitlabUrl) {
            $this->configError = 'GitLab URL not set!';
            return false;
        }

        if (empty($this->token)) {
            $this->configError = 'Authentication token is missing!';
            return false;
        }

        try {
            $user = $this->makeSingleGitLabGetRequest('/user');
        } catch (\Exception $e) {
            $this->configError = 'The GitLab authentication failed with message: ' . hsc($e->getMessage());
            return false;
        }
        $this->user = $user;

        return true;
    }

    /**
     * Make a single GET request to GitLab
     *
     * @param string $endpoint The endpoint as specifed in the gitlab documentatin (with leading slash!)
     *
     * @return array The response as array
     * @throws HTTPRequestException
     */
    protected function makeSingleGitLabGetRequest($endpoint)
    {
        return $this->makeGitLabRequest($endpoint, [], 'GET');
    }

    /**
     * Make a request to GitLab
     *
     * @param string $endpoint The endpoint as specifed in the gitlab documentatin (with leading slash!)
     * @param array  $data
     * @param string $method   the http method to make, defaults to 'GET'
     * @param array  $headers  an array of additional headers to send along
     *
     * @return array|int The response as array or the number of an occurred error if it is in @param
     *                   $errorsToBeReturned or an empty array if the error is not in @param $errorsToBeReturned
     *
     * @throws HTTPRequestException
     */
    protected function makeGitLabRequest($endpoint, array $data, $method, array $headers = [])
    {
        $url = $this->gitlabUrl . '/api/v4' . strtolower($endpoint);
        $defaultHeaders = [
            'PRIVATE-TOKEN' => $this->token,
            'Content-Type' => 'application/json',
        ];

        $requestHeaders = array_merge($defaultHeaders, $headers);
        return $this->makeHTTPRequest($this->dokuHTTPClient, $url, $requestHeaders, $data, $method);
    }

    /**
     * @param Form $configForm
     *
     * @return void
     */
    public function hydrateConfigForm(Form $configForm)
    {
        $link = 'https://<em>your.gitlab.host</em>/profile/personal_access_tokens';
        if (null !== $this->gitlabUrl) {
            $url = $this->gitlabUrl . '/profile/personal_access_tokens';
            $link = "<a href=\"$url\">$url</a>";
        }

        $message = '<p>';
        $message .= $this->configError;
        $message .= "Please go to $link and generate a new token for this plugin with the <b>api</b> scope.";
        $message .= '</p>';

        $configForm->addHTML($message);
        $configForm->addTextInput('gitlab_url', 'GitLab Url')->val($this->gitlabUrl);
        $configForm->addTextInput('gitlab_token', 'GitLab AccessToken')->useInput(false);
    }

    public function handleAuthorization()
    {
        global $INPUT;

        $token = $INPUT->str('gitlab_token');
        $url = $INPUT->str('gitlab_url');

        /** @var \helper_plugin_issuelinks_db $db */
        $db = plugin_load('helper', 'issuelinks_db');
        if (!empty($token)) {
            $db->saveKeyValuePair('gitlab_token', $token);
        }
        if (!empty($url)) {
            $db->saveKeyValuePair('gitlab_url', $url);
        }
    }

    public function getUserString()
    {
        $name = $this->user['name'];
        $url = $this->user['web_url'];

        return "<a href=\"$url\" target=\"_blank\">$name</a>";
    }

    /**
     * Get a list of all organisations a user is member of
     *
     * @return string[] the identifiers of the organisations
     */
    public function getListOfAllUserOrganisations()
    {
        $groups = $this->makeSingleGitLabGetRequest('/groups');

        return array_map(function ($group) {
            return $group['full_path'];
        }, $groups);
    }

    /**
     * @param $organisation
     *
     * @return Repository[]
     */
    public function getListOfAllReposAndHooks($organisation)
    {
        $projects = $this->makeSingleGitLabGetRequest("/groups/$organisation/projects?per_page=100");
        $repositories = [];
        foreach ($projects as $project) {
            $repo = new Repository();
            $repo->full_name = $project['path_with_namespace'];
            $repo->displayName = $project['name'];
            try {
                $endpoint = "/projects/$organisation%2F{$project['path']}/hooks?per_page=100";
                $repoHooks = $this->makeSingleGitLabGetRequest($endpoint);
            } catch (HTTPRequestException $e) {
                $repo->error = (int)$e->getCode();
            }

            $repoHooks = array_filter($repoHooks, [$this, 'isOurIssueHook']);
            $ourIsseHook = reset($repoHooks);
            if (!empty($ourIsseHook)) {
                $repo->hookID = $ourIsseHook['id'];
            }

            $repositories[] = $repo;
        }

        return $repositories;
    }

    public function createWebhook($project)
    {
        $secret = md5(openssl_random_pseudo_bytes(32));
        $data = [
            'url' => self::WEBHOOK_URL,
            'enable_ssl_verification' => true,
            'token' => $secret,
            'push_events' => false,
            'issues_events' => true,
            'merge_requests_events' => true,
        ];

        try {
            $encProject = urlencode($project);
            $data = $this->makeGitLabRequest("/projects/$encProject/hooks", $data, 'POST');
            $status = $this->dokuHTTPClient->status;
            /** @var \helper_plugin_issuelinks_db $db */
            $db = plugin_load('helper', 'issuelinks_db');
            $db->saveWebhook('gitlab', $project, $data['id'], $secret);
        } catch (HTTPRequestException $e) {
            $data = $e->getMessage();
            $status = $e->getCode();
        }

        return ['data' => $data, 'status' => $status];
    }

    public function deleteWebhook($project, $hookid)
    {
        /** @var \helper_plugin_issuelinks_db $db */
        $db = plugin_load('helper', 'issuelinks_db');
        $encProject = urlencode($project);
        $endpoint = "/projects/$encProject/hooks/$hookid";
        try {
            $data = $this->makeGitLabRequest($endpoint, [], 'DELETE');
            $status = $this->dokuHTTPClient->status;
            $db->deleteWebhook('gitlab', $project, $hookid);
        } catch (HTTPRequestException $e) {
            $data = $e->getMessage();
            $status = $e->getCode();
        }

        return ['data' => $data, 'status' => $status];
    }

    /**
     * Get the url to the given issue at the given project
     *
     * @param      $projectId
     * @param      $issueId
     * @param bool $isMergeRequest ignored, GitHub routes the requests correctly by itself
     *
     * @return string
     */
    public function getIssueURL($projectId, $issueId, $isMergeRequest)
    {
        return $this->gitlabUrl . '/' . $projectId . ($isMergeRequest ? '/merge_requests/' : '/issues/') . $issueId;
    }

    /**
     * @param string $issueSyntax
     *
     * @return Issue
     */
    public function parseIssueSyntax($issueSyntax)
    {
        $isMergeRequest = false;
        $projectIssueSeperator = '#';
        if (strpos($issueSyntax, '!') !== false) {
            $isMergeRequest = true;
            $projectIssueSeperator = '!';
        }
        list($projectKey, $issueId) = explode($projectIssueSeperator, $issueSyntax);
        $issue = Issue::getInstance('gitlab', $projectKey, $issueId, $isMergeRequest);
        $issue->getFromDB();
        return $issue;
    }

    public function retrieveIssue(Issue $issue)
    {
        $notable = $issue->isMergeRequest() ? 'merge_requests' : 'issues';
        $repoUrlEnc = rawurlencode($issue->getProject());
        $endpoint = '/projects/' . $repoUrlEnc . '/' . $notable . '/' . $issue->getKey();
        $info = $this->makeSingleGitLabGetRequest($endpoint);
        $this->setIssueData($issue, $info);

        if ($issue->isMergeRequest()) {
            $mergeRequestText = $issue->getSummary() . ' ' . $issue->getDescription();
            $issues = $this->parseMergeRequestDescription($issue->getProject(), $mergeRequestText);
            /** @var \helper_plugin_issuelinks_db $db */
            $db = plugin_load('helper', 'issuelinks_db');
            $db->saveIssueIssues($issue, $issues);
        }
        $endpoint = '/projects/' . $repoUrlEnc . '/labels';
        $projectLabelData = $this->makeSingleGitLabGetRequest($endpoint);
        foreach ($projectLabelData as $labelData) {
            $issue->setLabelData($labelData['name'], $labelData['color']);
        }
    }

    /**
     * @param Issue $issue
     * @param array $info
     */
    protected function setIssueData(Issue $issue, $info)
    {
        $issue->setSummary($info['title']);
        $issue->setDescription($info['description']);

        $issue->setType($this->getTypeFromLabels($info['labels']));
        $issue->setStatus($info['state']);
        $issue->setUpdated($info['updated_at']);
        $issue->setLabels($info['labels']);
        if (!empty($info['milestone'])) {
            $issue->setVersions([$info['milestone']['title']]);
        }
        if (!empty($info['milestone'])) {
            $issue->setDuedate($info['duedate']);
        }

        if (!empty($info['assignee'])) {
            $issue->setAssignee($info['assignee']['name'], $info['assignee']['avatar_url']);
        }
    }

    protected function getTypeFromLabels(array $labels)
    {
        $bugTypeLabels = ['bug'];
        $improvementTypeLabels = ['enhancement'];
        $storyTypeLabels = ['feature'];

        if (count(array_intersect($labels, $bugTypeLabels))) {
            return 'bug';
        }

        if (count(array_intersect($labels, $improvementTypeLabels))) {
            return 'improvement';
        }

        if (count(array_intersect($labels, $storyTypeLabels))) {
            return 'story';
        }

        return 'unknown';
    }

    /**
     * Parse a string for issue-ids
     *
     * Currently only parses issues for the same repo and jira issues
     *
     * @param string $currentProject
     * @param string $description
     *
     * @return array
     */
    public function parseMergeRequestDescription($currentProject, $description)
    {
        $issues = [];

        $issueOwnRepoPattern = '/(?:\W|^)#([1-9]\d*)\b/';
        preg_match_all($issueOwnRepoPattern, $description, $gitlabMatches);
        foreach ($gitlabMatches[1] as $issueId) {
            $issues[] = [
                'service' => 'gitlab',
                'project' => $currentProject,
                'issueId' => $issueId,
            ];
        }

        $jiraMatches = [];
        $jiraPattern = '/[A-Z0-9]+-[1-9]\d*/';
        preg_match_all($jiraPattern, $description, $jiraMatches);
        foreach ($jiraMatches[0] as $match) {
            list($project, $issueId) = explode('-', $match);
            $issues[] = [
                'service' => 'jira',
                'project' => $project,
                'issueId' => $issueId,
            ];
        }
        return $issues;
    }

    public function retrieveAllIssues($projectKey, &$startat = 0)
    {
        $perPage = 100;
        $page = ceil(($startat + 1) / $perPage);
        $endpoint = '/projects/' . urlencode($projectKey) . "/issues?page=$page&per_page=$perPage";
        $issues = $this->makeSingleGitLabGetRequest($endpoint);
        $this->total = $this->estimateTotal($perPage, count($issues));
        $mrEndpoint = '/projects/' . urlencode($projectKey) . "/merge_requests?page=$page&per_page=$perPage";
        $mrs = $this->makeSingleGitLabGetRequest($mrEndpoint);
        $this->total += $this->estimateTotal($perPage, count($mrs));
        $retrievedIssues = [];
        try {
            foreach ($issues as $issueData) {
                $issue = Issue::getInstance('gitlab', $projectKey, $issueData['iid'], false);
                $this->setIssueData($issue, $issueData);
                $issue->saveToDB();
                $retrievedIssues[] = $issue;
            }
            $startat += $perPage;
        } catch (\InvalidArgumentException $e) {
            dbglog($e->getMessage());
            dbglog($issueData);
        }

        try {
            foreach ($mrs as $mrData) {
                $issue = Issue::getInstance('gitlab', $projectKey, $mrData['iid'], true);
                $this->setIssueData($issue, $mrData);
                $issue->saveToDB();
                $retrievedIssues[] = $issue;
                $issueText = $issue->getSummary() . ' ' . $issue->getDescription();
                $issues = $this->parseMergeRequestDescription($projectKey, $issueText);
                /** @var \helper_plugin_issuelinks_db $db */
                $db = plugin_load('helper', 'issuelinks_db');
                $db->saveIssueIssues($issue, $issues);
            }
        } catch (\InvalidArgumentException $e) {
            dbglog($e->getMessage());
            dbglog($mrData);
        }

        return $retrievedIssues;
    }

    /**
     * Estimate the total amount of results
     *
     * @param int $perPage amount of results per page
     * @param int $default what is returned if the total can not be calculated otherwise
     *
     * @return
     */
    protected function estimateTotal($perPage, $default)
    {
        $headers = $this->dokuHTTPClient->resp_headers;

        if (empty($headers['link'])) {
            return $default;
        }

        /** @var \helper_plugin_issuelinks_util $util */
        $util = plugin_load('helper', 'issuelinks_util');
        $links = $util->parseHTTPLinkHeaders($headers['link']);
        preg_match('/page=(\d+)$/', $links['last'], $matches);
        if (!empty($matches[1])) {
            return $matches[1] * $perPage;
        }
        return $default;
    }

    /**
     * Get the total of issues currently imported by retrieveAllIssues()
     *
     * This may be an estimated number
     *
     * @return int
     */
    public function getTotalIssuesBeingImported()
    {
        return $this->total;
    }

    /**
     * Do all checks to verify that the webhook is expected and actually ours
     *
     * @param $webhookBody
     *
     * @return true|RequestResult true if the the webhook is our and should be processed RequestResult with explanation
     *                            otherwise
     */
    public function validateWebhook($webhookBody)
    {
        global $INPUT;
        $requestToken = $INPUT->server->str('HTTP_X_GITLAB_TOKEN');

        $data = json_decode($webhookBody, true);
        dbglog($data, __FILE__ . ': ' . __LINE__);
        $project = $data['project']['path_with_namespace'];

        /** @var \helper_plugin_issuelinks_db $db */
        $db = plugin_load('helper', 'issuelinks_db');
        $secrets = array_column($db->getWebhookSecrets('gitlab', $project), 'secret');
        $tokenMatches = false;
        foreach ($secrets as $secret) {
            if ($secret === $requestToken) {
                $tokenMatches = true;
                break;
            }
        }

        if (!$tokenMatches) {
            return new RequestResult(403, 'Token does not match!');
        }

        return true;
    }

    /**
     * Handle the contents of the webhooks body
     *
     * @param $webhookBody
     *
     * @return RequestResult
     */
    public function handleWebhook($webhookBody)
    {
        $data = json_decode($webhookBody, true);

        $allowedEventTypes = ['issue', 'merge_request'];
        if (!in_array($data['event_type'], $allowedEventTypes)) {
            return new RequestResult(406, 'Invalid event type: ' . $data['event_type']);
        }
        $isMergeRequest = $data['event_type'] === 'merge_request';
        $issue = Issue::getInstance(
            'gitlab',
            $data['project']['path_with_namespace'],
            $data['object_attributes']['iid'],
            $isMergeRequest
        );
        $issue->getFromService();

        return new RequestResult(200, 'OK.');
    }

    /**
     * See if this is a hook for issue events, that has been set by us
     *
     * @param array $hook the hook data coming from github
     *
     * @return bool
     */
    protected function isOurIssueHook($hook)
    {
        if ($hook['url'] !== self::WEBHOOK_URL) {
            return false;
        }

        if (!$hook['enable_ssl_verification']) {
            return false;
        }

        if ($hook['push_events']) {
            return false;
        }

        if (!$hook['issues_events']) {
            return false;
        }

        if (!$hook['merge_requests_events']) {
            return false;
        }

        return true;
    }
}
