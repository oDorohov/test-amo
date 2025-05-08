<?php
namespace app\models;

use Yii;
use yii\base\InvalidConfigException;
 
class AmoCrm
{
    private const ENTITY_LEADS = 'leads';
    private const ENTITY_CONTACTS = 'contacts';
    private const TOKEN_FILE_MODE = 0600;

    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $domain;
    private $tokenPath;

    public function __construct()
    {
        $this->validateConfig();
    }

    /**
     * @throws InvalidConfigException
     */
    private function validateConfig(): void
    {
        $cfg = Yii::$app->params['amo'] ?? [];

        $requiredKeys = ['clientId', 'clientSecret', 'redirectUri', 'domain', 'tokenPath'];
        foreach ($requiredKeys as $key) {
            if (empty($cfg[$key])) {
                throw new InvalidConfigException("Missing required amoCRM config key: $key");
            }
        }

        $this->clientId = $cfg['clientId'];
        $this->clientSecret = $cfg['clientSecret'];
        $this->redirectUri = $cfg['redirectUri'];
        $this->domain = $cfg['domain'];
        $this->tokenPath = Yii::getAlias($cfg['tokenPath']);
    }

    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
        ];
        return "https://www.amocrm.ru/oauth?" . http_build_query($params);
    }

    public function getTokenByCode(string $code): void
    {
        $this->sendTokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
    }

    public function getTokenByRefreshToken(): void
    {
        $tokens = $this->getTokens();
        $this->sendTokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
        ]);
    }

    private function sendTokenRequest(array $data): void
    {
        $data += [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
        ];

        $url = "https://{$this->domain}/oauth2/access_token";
        $response = $this->executeCurlRequest($url, 'POST', $data);

        if ($response['httpCode'] !== 200) {
            throw new \RuntimeException("OAuth Error ({$response['httpCode']}): " . $response['body']);
        }

        $this->saveTokens(json_decode($response['body'], true));
    }

    private function saveTokens(array $tokens): void
    {
        if (file_put_contents($this->tokenPath, json_encode($tokens), LOCK_EX) === false) {
            throw new \RuntimeException("Failed to save tokens to {$this->tokenPath}");
        }
        chmod($this->tokenPath, self::TOKEN_FILE_MODE);
    }

    private function getTokens(): array
    {
        if (!file_exists($this->tokenPath)) {
            throw new \RuntimeException("Token file not found: {$this->tokenPath}");
        }

        $content = file_get_contents($this->tokenPath);
        $tokens = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON in token file: " . json_last_error_msg());
        }

        return $tokens;
    }

    public function handleWebhook(array $data): void
    {
        Yii::trace($data);

        if (isset($data['leads'])) {
            $this->processLeads($data['leads']);
        }

        // if (isset($data['contacts'])) {
            // $this->processContacts($data['contacts']);
        // }
    }

    private function processLeads(array $leadsData): void
    {
        foreach (['add', 'update'] as $action) {
            if (empty($leadsData[$action])) continue;

            foreach ($leadsData[$action] as $lead) {

				$this->processLead($lead, $action);
            }
        }
    } 

	private function processLead(array $lead, string $action): void
		{
			$leadId = $lead['id'];
			$leadName = $lead['name'] ?? '(без названия)';

			if ($action === 'add') {
				$responsible = $this->getResponsibleName($lead['responsible_user_id'] ?? null);
				$createdAt = Yii::$app->formatter->asDatetime($lead['created_at'], 'php:d.m.Y H:i:s');
				$contactName = 'Контакт не указан';
				$leadObject = $this->getLeadById($lead['id'],['with'=>'contacts']);
				// Получаем контакт из вебхука или API
				if (!empty($leadObject['_embedded']['contacts'][0]['id'])) {
					$contactId = $leadObject['_embedded']['contacts'][0]['id'];
					$contact = $this->getContactById($contactId);
					$contactName = $contact['name'] ?? 'Без имени';
				}

				$noteText = "Сделка создана: '{$leadName}'\n"
						  . "Контакт: {$contactName}\n"
						  . "Дата создания: {$createdAt}\n"
						  . "Ответственный: {$responsible}";
				
				$this->addNote($leadId, $noteText, self::ENTITY_LEADS);
				return;
			}

			// Обработка обновления сделки
			$lastEvent = $this->getLastLeadEvent($leadId);
			if (!$lastEvent) return;
			
			if($this->isDuplicate($lastEvent['id'])) return;
			
			
			$changes = $this->formatEventChanges($lastEvent);
			if (empty($changes)) return;

			try {
				$lastModified = Yii::$app->formatter->asDatetime(
					$lead['last_modified'] ?? time(),
					'php:d.m.Y H:i:s'
				);
			} catch (\Exception $e) {
				Yii::error("Ошибка форматирования даты: " . $e->getMessage());
				$lastModified = 'неизвестно';
			}

			$noteText = "Изменения в сделке '{$leadName}'\nДата изменения: {$lastModified}:\n" 
					   . implode("\n", $changes);
			$this->addNote($leadId, $noteText, self::ENTITY_LEADS);
		}


    private function processContacts(array $contactsData): void
    {
        foreach (['add', 'update'] as $action) {
            if (empty($contactsData[$action])) continue;

            foreach ($contactsData[$action] as $contact) {
                $this->processContact($contact, $action);
            }
        }
    }

    private function processContact(array $contact, string $action): void
    {
        $contactId = $contact['id'];
        $now = date('d.m.Y H:i:s');

        if ($action === 'add') {
            $responsible = $this->getResponsibleName($contact['responsible_user_id'] ?? null);
            $name = $contact['name'] ?? '(без имени)';
            $noteText = "Контакт создан: {$name}\n Время создания: {$now}\n Ответственный: {$responsible}";
        } else {
            $oldContact = $this->getContactById($contactId);
            $changedFields = $this->getChangedFields($oldContact, $contact);
            if (empty($changedFields)) return;

            $noteText = "Контакт изменен: {$name}\n Время создания: {$now}\n Ответственный: {$responsible}";
        }

        $this->addNote($contactId, $noteText, self::ENTITY_CONTACTS);
    }

    private function getResponsibleName(?int $responsibleId): string
    {
        if (!$responsibleId) return 'неизвестен';

        try {
            $response = $this->sendAuthorizedRequest("users/{$responsibleId}");
            return $response['name'] ?? 'неизвестен';
        } catch (\RuntimeException $e) {
            Yii::error("User fetch error: " . $e->getMessage(), 'amo');
            return 'неизвестен';
        }
    }

    private function getChangedFields(array $oldData, array $newData): array
    {
        $ignoredKeys = ['id', 'updated_at', 'updated_by'];
        $changedFields = [];

        foreach ($newData as $key => $newValue) {
            if (in_array($key, $ignoredKeys)) continue;

            $oldValue = $oldData[$key] ?? null;
            if ($newValue === $oldValue) continue;

            if (in_array($key, ['date_create', 'last_modified']) && $newValue) {
                $newValue = date('Y-m-d H:i:s', $newValue);
            }

            $changedFields[] = "$key: {$newValue}";
        }

        return $changedFields;
    }

    private function getLastLeadEvent(int $leadId): ?array
    {
        try {
            $response = $this->sendAuthorizedRequest('events', [
                'filter[entity]' => self::ENTITY_LEADS,
                'filter[entity_id]' => $leadId,
                'limit' => 1,
            ]);

            return $response['_embedded']['events'][0] ?? null;
        } catch (\RuntimeException $e) {
            Yii::error("Event fetch error: " . $e->getMessage(), 'amo');
            return null;
        }
    }

    private function addNote(int $entityId, string $text, string $entityType): void
    {
        try {
            $payload = [[
                'note_type' => 'common',
                'params' => ['text' => $text],
            ]];

            $this->sendAuthorizedRequest(
                "{$entityType}/{$entityId}/notes",
                json_encode($payload),
                'POST',
                ['Content-Type: application/json']
            );

            Yii::trace('Примечание успешно добавлено');
        } catch (\RuntimeException $e) {
            Yii::error("Note add error: " . $e->getMessage(), 'amo');
        }
    }

    private function sendAuthorizedRequest(
        string $endpoint,
        $data = null,
        string $method = 'GET',
        array $headers = []
    ): array {
        $tokens = $this->getTokens();
        $headers[] = 'Authorization: Bearer ' . $tokens['access_token'];

        $url = "https://{$this->domain}/api/v4/{$endpoint}";
        $response = $this->executeCurlRequest($url, $method, $data, $headers);

        if ($response['httpCode'] !== 200) {
            throw new \RuntimeException("API request failed: {$response['httpCode']} - {$response['body']}");
        }

        return json_decode($response['body'], true) ?? [];
    }

    private function executeCurlRequest(
        string $url,
        string $method = 'GET',
        $data = null,
        array $headers = []
    ): array {
        $ch = curl_init($url);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ];

        if ($data !== null) {
            if (is_array($data)) {
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            } else {
                $options[CURLOPT_POSTFIELDS] = $data;
                $headers[] = 'Content-Type: application/json';
            }
        }

        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException("CURL error: $error");
        }

        return [
            'httpCode' => $httpCode,
            'body' => $body,
        ];
    }

	private function formatEventChanges(array $event): array
	{
		$result = [];
		Yii::trace($event);
		if (isset($event['value_before'], $event['value_after']) 
			&& is_array($event['value_before']) 
			&& is_array($event['value_after'])
		) {
			// Собираем все уникальные ключи из обоих массивов
			$allKeys = array_unique(array_merge(
				array_keys($event['value_after']),
				array_keys($event['value_before'])
			));

			foreach ($allKeys as $index) {
				$afterData = $event['value_after'][$index] ?? [];
				$beforeData = $event['value_before'][$index] ?? [];

				// Обрабатываем добавленные поля
				foreach ($afterData as $fieldType => $fieldData) {
					if (in_array($fieldType, ['note'])) {
						continue;
					}
					if (!isset($beforeData[$fieldType])) {
						$fieldName = $this->getFieldName($fieldType);
						$value = $this->extractFieldValue($fieldData);
						$result[] = "Добавлено {$fieldName}: {$value}";
					}
				}

				// Обрабатываем измененные и удаленные поля
				foreach ($beforeData as $fieldType => $beforeValue) {
					if (in_array($fieldType, ['note'])) {
						continue;
					}
					$afterValue = $afterData[$fieldType] ?? null;
					
					if (!isset($afterData[$fieldType])) {
						$fieldName = $this->getFieldName($fieldType);
						$value = $this->extractFieldValue($beforeValue);
						$result[] = "Удалено {$fieldName}: {$value}";
					} elseif ($this->extractFieldValue($beforeValue) !== $this->extractFieldValue($afterValue)) {
						$fieldName = $this->getFieldName($fieldType);
						$result[] = "{$fieldName}: было {$this->extractFieldValue($beforeValue)} → стало {$this->extractFieldValue($afterValue)}";
					}
				}
			}
		}

		// Обработка специальных типов событий
		switch ($event['type'] ?? '') {
			case 'lead_added':
				$result[] = "Сделка создана"; 
				break;
				
			case 'entity_linked':
				$entityType = $event['value_after'][0]['link']['entity']['type'] ?? '?';
				$entityId = $event['value_after'][0]['link']['entity']['id'] ?? '?';
				$pluralType = $this->pluralizeEntityType($entityType);
				$objectName = $this->getLinkedObjectName($pluralType, $entityId);
				$typeName = $this->getEntityTypeName($entityType);
				$result[] = "Привязан объект: {$typeName}:{$objectName}";
				break;
				
			case 'entity_unlinked':
				$entityType = $event['value_before'][0]['link']['entity']['type'] ?? '?';
				$entityId = $event['value_before'][0]['link']['entity']['id'] ?? '?';
				$pluralType = $this->pluralizeEntityType($entityType);
				$objectName = $this->getLinkedObjectName($pluralType, $entityId);
				$typeName = $this->getEntityTypeName($entityType);
				$result[] = "Отвязан объект: {$typeName}:{$objectName}";
				break;
		}
		
		return $result;
	}
	
	private function pluralizeEntityType(string $type): string
	{
		$map = [
			'contact' => 'contacts',
			'contacts' => 'contacts',
			'lead' => 'leads',
			'leads' => 'leads',
			'company' => 'companies',
			'companies' => 'companies',
			'customer' => 'customers',
			'task' => 'tasks',
			'catalog' => 'catalogs',
		];
		
		return $map[strtolower($type)] ?? $type;
	}
	
	private function getEntityTypeName(string $apiType): string
	{
		$types = [
			'contacts' => 'Контакты',
			'leads' => 'Сделки',
			'companies' => 'Компании',
			'customers' => 'Покупатели',
			'catalogs' => 'Элементы каталогов',
			'contact' => 'Контакт',
			'lead' => 'Сделка',
			'company' => 'Компания',
			'customer' => 'Покупатель',
			'catalog' => 'Элемент каталога'
		];
		
		return $types[$apiType] ?? $apiType;
	}
	
	private function getLinkedObjectName(string $entityType, int $entityId): string
	{
		try {
			$response = $this->sendAuthorizedRequest("{$entityType}/{$entityId}");
			$data = $response['_embedded']['items'][0] ?? $response;
			$entityType = strtolower($entityType);

			switch ($entityType) {
				case 'contacts':
				case 'leads':
				case 'companies':
					return $data['name'] ?? 'Без названия';
				
				case 'customers':
					return $this->getCustomerName($data);
				
				case 'catalogs':
					return $this->getCatalogElementName($data);
				
				case 'tasks':
					return $this->getTaskName($data);
				
				default:
					return $this->getDefaultEntityName($entityType, $data);
			}
		} catch (\Exception $e) {
			Yii::error("Ошибка получения объекта {$entityType}#{$entityId}: " . $e->getMessage());
			return 'неизвестен';
		}
	}

    private function extractFieldValue(array $fieldData): string
    {
        if (isset($fieldData['sale'])) {
            return (string)$fieldData['sale'];
        }
        
        if (isset($fieldData['name'])) {
            return "'" . $fieldData['name'] . "'";
        }
        
        if (isset($fieldData['id'])) {
            return (string)$fieldData['id'];
        }

        
        return json_encode($fieldData, JSON_UNESCAPED_UNICODE);
    }

    private function getFieldName(string $fieldType): string
    {
        $names = [
            'lead_status' => 'Статус',
            'sale_field_value' => 'Сумма',
            'name_field_value' => 'Название',
            'responsible_user' => 'Ответственный',
            'custom_fields' => 'Доп. поле',
			'link'=>'ссылка',
        ];
        
        return $names[$fieldType] ?? $fieldType;
    }

	private function getLeadById(int $leadId, array $params = []): array
	{
		try {
			$query = http_build_query($params);
			return $this->sendAuthorizedRequest("leads/{$leadId}?" . $query);
		} catch (\RuntimeException $e) {
			Yii::error("Lead fetch error: " . $e->getMessage(), 'amo');
			return [];
		}
	}

    private function getContactById(int $contactId): array
    {
        try {
            return $this->sendAuthorizedRequest("contacts/{$contactId}");
        } catch (\RuntimeException $e) {
            Yii::error("Contact fetch error: " . $e->getMessage(), 'amo');
            return [];
        }
    }
	
	private function isDuplicate($key) {
		$cache = \Yii::$app->cache;
		if ($cache->exists($key)) {
			return true;
		}
		$cache->set($key, true, 2); // кэшируем на 5 секунд
		return false;
	}
}