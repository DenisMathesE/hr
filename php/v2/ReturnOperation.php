<?php

namespace NW\WebService\References\Operations\Notification;

class TsReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW    = 1;
    public const TYPE_CHANGE = 2;

    protected $resellerId;
    protected $differences;
    protected $notificationType;
    protected $client;
    protected $templateData;

    /**
     * @throws \Exception
     */
    public function doOperation(): void
    {
        $data = (array)$this->getRequest('data');
        $result = $this->getDefaultResult();
        try {
            $this->setOperationData( $data );
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage(), 400);
        }

        $emailFrom = getResellerEmailFrom();
        // Получаем email сотрудников из настроек
        $emails = getEmailsByPermit($this->resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $email,
                           'subject'   => __('complaintEmployeeEmailSubject', $this->templateData, $this->resellerId),
                           'message'   => __('complaintEmployeeEmailBody', $this->templateData, $this->resellerId),
                    ],
                ], $this->resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }

        // Шлём клиентское уведомление, только если произошла смена статуса
        if ($this->notificationType === self::TYPE_CHANGE && isset($this->differences['to'])) {
            if (!empty($emailFrom) && !empty($this->client->email)) {
                MessagesClient::sendMessage([
                    0 => [ // MessageTypes::EMAIL
                           'emailFrom' => $emailFrom,
                           'emailTo'   => $this->client->email,
                           'subject'   => __('complaintClientEmailSubject', $this->templateData, $this->resellerId),
                           'message'   => __('complaintClientEmailBody', $this->templateData, $this->resellerId),
                    ],
                ], $this->resellerId, $this->client->id, NotificationEvents::CHANGE_RETURN_STATUS, $this->differences['to']);
                $result['notificationClientByEmail'] = true;
            }

            if (!empty($this->client->mobile)) {
                $res = NotificationManager::send($this->resellerId, $this->client->id, NotificationEvents::CHANGE_RETURN_STATUS, $this->differences['to'], $this->templateData, $error);
                if ($res) {
                    $result['notificationClientBySms']['isSent'] = true;
                }
                if (!empty($error)) {
                    $result['notificationClientBySms']['message'] = $error;
                }
            }
        }

        return $result;
    }

    protected function getDefaultResult(): array
    {
        return [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail'   => false,
            'notificationClientBySms'     => [
                'isSent'  => false,
                'message' => '',
            ],
        ];
    }

    protected function setOperationData(array $data): array {
        $this->resellerId = (int) ($data['resellerId'] ?? 0);
        $this->notificationType = (int) ($data['notificationType'] ?? 0);
        $this->differences = [];
        if ( isset($data['differences']['from']) && isset($data['differences']['to']) ) {
            $this->differences = ['from' => (int) $data['differences']['from'], 'to' => (int) $data['differences']['to']];
        }

        if ( !$this->resellerId ) {
            throw new \Exception('Empty resellerId!', 400);
        }
        if ( !$this->notificationType ) {
            throw new \Exception('Empty notificationType!', 400);
        }

        if ( $this->notificationType === self::TYPE_CHANGE && !count($this->differences)) {
            throw new \Exception('Empty differences!', 400);
        }

        $this->templateData = [
            'COMPLAINT_ID'       => (int) ($data['complaintId'] ?? 0),
            'COMPLAINT_NUMBER'   => (string) ($data['complaintNumber'] ?? ''),
            'CREATOR_ID'         =>  (int) ($data['creatorId'] ?? 0),
            'EXPERT_ID'          => (int) ($data['expertId'] ?? 0),
            'CLIENT_ID'          => (int) ($data['clientId'] ?? 0),
            'CONSUMPTION_ID'     => (int) ($data['consumptionId'] ?? 0),
            'CONSUMPTION_NUMBER' => (string) ($data['consumptionNumber'] ?? ''),
            'AGREEMENT_NUMBER'   => (string) ($data['agreementNumber'] ?? ''),
            'DATE'               => (string) (isset($data['date']) && strtotime($data['date']))?$data['date']:'',
        ];
        
        // Если хоть одна переменная для шаблона не задана, то не отправляем уведомления
        foreach ($this->templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new \Exception("Template Data ({$key}) is empty!", 500);
            }
        }

        //  после того как проверили входящие данные, обращаемся к базе
        $reseller = Seller::getById($this->resellerId);
        if ($reseller === null) {
            throw new \Exception('Seller not found!', 400);
        }

        $creator = Employee::getById($this->creatorId);
        if ($creator === null) {
            throw new \Exception('Creator not found!', 400);
        }

        $expert = Employee::getById($this->expertId);
        if ($expert === null) {
            throw new \Exception('Expert not found!', 400);
        }

        $this->client = Contractor::getById($this->clientId);
        // клиент обязательно должен быть покупателем и быть связанным с реселлером
        if ($this->client === null || $this->client->type !== Contractor::TYPE_CUSTOMER || $this->client->Seller->id !== $this->resellerId) {
            throw new \Exception('Client not found!', 400);
        }

        if ($this->notificationType === self::TYPE_NEW) {
            $differences = __('NewPositionAdded', null, $this->resellerId);
        } elseif ($this->notificationType === self::TYPE_CHANGE ) {
            $differences = __('PositionStatusHasChanged', [
                    'FROM' => Status::getName($this->differences['from']),
                    'TO'   => Status::getName($this->differences['to']),
                ], $this->resellerId);
        }

        $this->templateData['CREATOR_NAME'] = $creator->getFullName();
        $this->templateData['EXPERT_NAME'] = $expert->getFullName();
        $this->templateData['CLIENT_NAME'] = $this->client->getFullName();
        $this->templateData['DIFFERENCES'] = $differences;
    }
}
