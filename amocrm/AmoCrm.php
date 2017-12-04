<?php
/**
 * Created by PhpStorm.
 * User: mrcake
 * Date: 12/2/17
 * Time: 12:24 AM
 */

namespace amocrm;

class AmoCrm
{
    const NEW_DEAL_NAME = 'Заявка с сайта';

    protected static function checkInput()
    {
        return $_SERVER['REQUEST_METHOD'] == 'POST';
    }

    /**
     * Creates new deal with contact based on form data or renders the form
     *
     * @return string
     */
    public static function createDeal() : string
    {
        $form = new View('contact_form.php');

        $requestData = $_POST;

        if (!static::checkInput()) {
            return $form->render();
        }

        $validator = new RequestValidator($requestData);
        $validator->setRules([
            'email' => ['required', 'email'],
            'phone' => 'required'
        ]);

        if (!$validator->validate()) {
            $requestData['errors'] = $validator->getErrors();

            return $form->render($requestData);
        }

        $apiClient = new ApiClient;
        $apiRepo = new AmoCrmRepo($apiClient);

        try {
            //auth api user(admin by default)
            $apiClient->authRequest();

            $contactInSystem = $apiRepo->findContact(
                $validator->getField('email'),
                $validator->getField('phone')
            );

//            if (!$contactInSystem) {
        if (true) {
                $createdContact = $apiRepo->createContact($validator->getFields());
                $contactId = $createdContact->id;

                $userId = $apiRepo->getBusylessUser();

            } else {
                [$contactId, $userId] = $contactInSystem;
            }

            $dealResponse = $apiRepo->createDeal(self::NEW_DEAL_NAME, $userId);

            $linked = $apiRepo->linkDealToContact($contactId, $dealResponse->id);

            $task = $apiRepo->createTaskByDeal($dealResponse->id);
            $taskId = $task->tasks->add[0]->id;

            $requestData['success'] = sprintf(static::successMessage(), $contactId, $dealResponse->id, $taskId);

        } catch (AmoCrmException $e) {

            $requestData['errors'] = [$e->getMessage()];
            $requestData['errors'][] = static::defaultErrorMessage();

        } finally {
            return $form->render($requestData);
        }

    }

    public static function getNotBusyUser(AmoCrmClientInterface $apiClient, $users)
    {
        $usersToDeals = [];
        //get user with minimum deals(leads)
        foreach ($users as $user) {
            $deals = $apiClient->getDeals(['responsible_user_id' => $user->id]);
            $mainContacts = [];
            if ($deals && $deals->leads) {
                foreach ($deals->leads as $lead) {
                    if (!in_array($lead->main_contact_id, $mainContacts)) {
                        $mainContacts[] = $lead->main_contact_id;
                    }
                }
            }
            $usersToDeals[$user->id] = count($mainContacts);
        }
        asort($usersToDeals, SORT_NUMERIC);
        $sortedUsers = array_keys($usersToDeals);
        return array_shift($sortedUsers);
    }

    protected static function defaultErrorMessage()
    {
        return 'amoCrm error';
    }

    protected static function successMessage()
    {
        return 'Contact ID: %1$d. Lead ID: %2$d. Task ID: %3$d';
    }
}