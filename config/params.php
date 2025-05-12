<?php

return [
    'adminEmail' => 'admin@example.com',
    'senderEmail' => 'noreply@example.com',
    'senderName' => 'Example.com mailer',
	'amo' => [
        'clientId' => '**',
        'clientSecret' => '**',
        'redirectUri' => 'https://test-yii.ru/amo/callback',
        'domain' => '**.amocrm.ru',
		'tokenPath' =>'@runtime/json_token.json',
    ], 
];
