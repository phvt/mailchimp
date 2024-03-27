<?php

namespace Sup7even\Mailchimp\Service;

use DrewM\MailChimp\MailChimp;
use Psr\Log\LoggerInterface;
use Sup7even\Mailchimp\Domain\Model\Dto\ExtensionConfiguration;
use Sup7even\Mailchimp\Domain\Model\Dto\FormDto;
use Sup7even\Mailchimp\Exception\CampaignNotReadyException;
use Sup7even\Mailchimp\Exception\GeneralException;
use Sup7even\Mailchimp\Exception\MemberExistsException;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class ApiService
{
    protected MailChimp $api;
    protected LoggerInterface $logger;
    protected string $apiKey = '';

    public function __construct($usedApiKeyHash = null)
    {
        require_once(ExtensionManagementUtility::extPath('mailchimp', 'Resources/Private/Contrib/MailChimp/MailChimp.php'));

        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $this->apiKey = $usedApiKeyHash ? $extensionConfiguration->getApiKeyByHash($usedApiKeyHash) : $extensionConfiguration->getFirstApiKey();
        $curlProxy = $extensionConfiguration->getProxy();
        $curlProxyPort = $extensionConfiguration->getProxyPort();

        $this->api = new MailChimp($this->apiKey, $curlProxy, $curlProxyPort);
        if ($extensionConfiguration->isForceIp4()) {
            $this->api->forceIpAddressv4();
        }
        $this->logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    /**
     * Get all lists
     *
     * @param int $maxCount max lists to be returned
     */
    public function getLists(int $maxCount = 50): array
    {
        $groups = [];
        $list = $this->api->get('lists', ['count' => $maxCount]);

        if (is_array($list) && array_key_exists('lists', $list)) {
            foreach ($list['lists'] as $item) {
                $groups[$item['id']] = $item['name'];
            }
        }
        return $groups;
    }

    /**
     * @return array|false
     */
    public function getList(string $list)
    {
        return $this->api->get('lists/' . $list);
    }

    /**
     * Get all interest groups of a given list
     *
     * @return array
     */
    public function getInterestLists(string $listId)
    {
        $groups = [];
        $list = $this->api->get('lists/' . $listId . '/interest-categories/');

        foreach ($list['categories'] as $group) {
            $groups[$group['id']] = $group['title'];
        }
        return $groups;
    }

    /**
     * Get all interest categories of a given list & interest
     */
    public function getCategories(string $listId, string $interestId): array
    {
        $groupData = $this->api->get('lists/' . $listId . '/interest-categories/' . $interestId . '/');
        $result = [
            'title' => $groupData['title'],
            'type' => $groupData['type'],
        ];

        $list = $this->api->get('lists/' . $listId . '/interest-categories/' . $interestId . '/interests', ['count' => 100]);
        if (isset($list['interests']) && is_array($list['interests'])) {
            foreach ($list['interests'] as $group) {
                $result['options'][$group['id']] = $group['name'];
            }
        }
        return $result;
    }

    /**
     * Register a user
     * @throws GeneralException
     * @throws MemberExistsException
     */
    public function register(string $listId, FormDto $form, bool $doubleOptIn = true): void
    {
        $data = $this->getRegistrationData($listId, $form, $doubleOptIn);
        $response = $this->api->post("lists/$listId/members", $data);

        if ($response['status'] === 400 || $response['status'] === 401 || $response['status'] === 404) {
            $this->logger->error($response['status'] . ' ' . $response['detail']);
            $this->logger->error($response['detail'], (array)($response['errors'] ?? []));
            if ($response['title'] === 'Member Exists') {
                $getResponse = $this->api->get("lists/$listId/members/" . $this->api->subscriberHash($data['email_address']));
                if ($getResponse['status'] !== 'subscribed') {
                    $this->api->put("lists/$listId/members/" . $this->api->subscriberHash($data['email_address']), $data);
                } else {
                    throw new MemberExistsException($response['detail']);
                }
            } else {
                throw new GeneralException($response['detail']);
            }
        }
    }

    protected function getRegistrationData(string $listId, FormDto $form, bool $doubleOptIn): array
    {
        $data = [
            'email_address' => $form->getEmail(),
            'status' => $doubleOptIn ? 'pending' : 'subscribed',
            'merge_fields' => [
                'FNAME' => (!empty($form->getFirstName())) ? $form->getFirstName() : '',
                'LNAME' => (!empty($form->getLastName())) ? $form->getLastName() : '',
            ],
        ];
        $interestData = $this->getInterests($form);
        if ($interestData) {
            $data['interests'] = $interestData;
        }

        if (isset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['mailchimp']['memberData']) && is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['mailchimp']['memberData'])) {
            $_params = [
                'data' => &$data,
                'listId' => $listId,
                'form' => $form,
            ];
            foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['mailchimp']['memberData'] as $funcName) {
                GeneralUtility::callUserFunction($funcName, $_params, $this);
            }
        }
        return $data;
    }

    protected function getInterests(FormDto $form): array
    {
        $interestData = [];
        // multi interests
        $interests = $form->getInterests();
        if ($interests) {
            foreach ($interests as $id => $state) {
                if ($state) {
                    $interestData[$id] = true;
                }
            }
        }
        // single interests
        $interest = $form->getInterest();
        if ($interest) {
            $interestData[$interest] = true;
        }
        return $interestData;
    }

    /**
     * @param array $recipients
     * @param array $settings
     * @param string $type
     * @return string ID of the newly create campaign
     */
    public function addCampaign($recipients = [], $settings = [], $type = 'regular')
    {
        $response = $this->api->post('campaigns', ['type' => $type, 'recipients' => $recipients, 'settings' => (object)$settings]);
        if ($response['errors'] ?? false) {
            foreach($response['errors'] as $error) {
                $this->logger->error($error['field'] . ': ' . $error['message']);
            }
        }

        return $response['id'] ?? false;
    }

    /**
     * @param string $campaignId
     * @param string $html
     * @param string $plaintext
     * @param array $template
     * @return void
     */
    public function setCampaignContent(string $campaignId, $html = '', $plaintext = '', $template = null)
    {
        $this->api->put('campaigns/' . $campaignId . '/content', ['html' => $html, 'plain_text' => $plaintext] + ($template ? ['template' => $template] : []));
    }

    /**
     * @param string $campaignId
     * @return void
     */
    public function sendCampaign(string $campaignId)
    {
        $response = $this->api->post('campaigns/' . $campaignId . '/actions/send');

        if (($response['status'] ?? false) &&  ($response['status'] === 400 || $response['status'] === 401 || $response['status'] === 404)) {

            $checklist = $this->api->get('campaigns/' . $campaignId . '/send-checklist');

            if (!$checklist['is_ready']) {
                $problems = [];

                foreach($checklist['items'] as $item) {
                    if ($item['type'] === 'error') {
                        $problems []= $item['details'];
                    }
                }

                $this->logger->error($response['status'] . ' ' . $response['detail']);

                $detail = $response['detail'];
                $detail .= ' Problems: ' . count($problems) . '.';
                $index = 0;

                foreach ($problems as $problem) {
                    $detail .= ' ' . ++$index . ': ' . $problem;
                }

                throw new CampaignNotReadyException($detail);
            } else {
                throw new GeneralException($response['detail']);
            }
        }
    }

    /**
     * @param string $campaignId
     * @return void
     */
    public function deleteCampaign(string $campaignId)
    {
        $response = $this->api->delete('campaigns/' . $campaignId);
    }

    public function addSegment(string $listId, string $name = '', array $conditions = [])
    {
        $response = $this->api->post('lists/' . $listId . '/segments', ['name' => $name, 'options' => ['match' => 'any', 'conditions' => $conditions]]);

        if ($response['status'] === 400 || $response['status'] === 401 || $response['status'] === 404) {
            throw new GeneralException($response['detail']);
        }

        return $response['id'] ?? false;
    }

    public function deleteSegment(string $listId, string $segmentId)
    {
        $response = $this->api->delete('lists/' . $listId . '/segments/' .  $segmentId);
        return $response['id'] ?? false;
    }

    public function getSegmentMembers(string $listId, string $segmentId)
    {
        $response = $this->api->get('lists/' . $listId . '/segments/' . $segmentId . '/members?count=100');
        return $response['members'];
    }
}
