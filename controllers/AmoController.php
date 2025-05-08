<?php 

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\web\Response;
use app\models\AmoCrm;
 
class AmoController extends Controller
{
    public $enableCsrfValidation = false; // для webhook

    public function actionAuth()
    {
        $amo = new AmoCrm();
        return $this->redirect($amo->getAuthUrl());
    }
	
	public function actionRefreshToken()
    {
        $amo = new AmoCrm();
		$amo->getTokenByRefreshToken();
		return 'Refresh token successful';
    }

    public function actionCallback($code = null)
    {
        if (!$code) return 'No code provided';
        $amo = new AmoCrm();
        $amo->getTokenByCode($code);
        return 'Authorization successful';
    }

    public function actionWebhook()
	{	
		Yii::$app->response->format = Response::FORMAT_JSON;

		// Получаем данные из POST-запроса
		$data = Yii::$app->request->post();

		// Логируем данные для отладки
		

		$amo = new AmoCrm();
		return $amo->handleWebhook($data);
	}

}
