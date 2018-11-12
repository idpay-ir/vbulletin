<?php

if (!isset($GLOBALS['vbulletin']->db)) exit;

class vB_PaidSubscriptionMethod_idpay extends vB_PaidSubscriptionMethod
{
    var $supports_recurring = false;
    var $display_feedback = true;

    function verify_payment()
    {
        $this->registry->input->clean_array_gpc('r', array(
            'id' => TYPE_STR,
            'order_id' => TYPE_STR,
            'track_id' => TYPE_STR,
            'amount' => TYPE_STR
        ));

        if (!$this->test()) {
            $this->error = 'Payment processor not configured';
            return false;
        }

        $this->transaction_id = $this->registry->GPC['track_id'];
        if (!empty($this->registry->GPC['order_id'])) {
            $this->paymentinfo = $this->registry->db->query_first("
				SELECT paymentinfo.*, user.username
				FROM " . TABLE_PREFIX . "paymentinfo AS paymentinfo
				INNER JOIN " . TABLE_PREFIX . "user AS user USING (userid)
				WHERE hash = '" . $this->registry->db->escape_string($this->registry->GPC['order_id']) . "'
			");
            if (!empty($this->paymentinfo)) {
                $sub = $this->registry->db->query_first("SELECT * FROM " . TABLE_PREFIX . "subscription WHERE subscriptionid = " . $this->paymentinfo['subscriptionid']);
                $cost = unserialize($sub['cost']);
                $amount = floor($cost[0][cost][usd] * $this->settings['currency_rate']);

                $orderid = $this->registry->GPC['order_id'];
                $pid = $this->registry->GPC['id'];

                if (!empty($pid) && !empty($orderid)) {
                    $api_key = $this->settings['api_key'];
                    $sandbox = $this->settings['sandbox'] == 1 ? 'true' : 'false';

                    $data = array(
                        'id' => $pid,
                        'order_id' => $orderid,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1/payment/inquiry');
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Content-Type: application/json',
                        'X-API-KEY:' . $api_key,
                        'X-SANDBOX:' . $sandbox,
                    ));

                    $result = curl_exec($ch);
                    $result = json_decode($result);
                    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_status != 200) {
                        $this->error = sprintf('خطا هنگام بررسی وضعیت تراکنش. کد خطا: %s', $http_status);
                        return false;
                    }

                    $inquiry_status = empty($result->status) ? NULL : $result->status;
                    $inquiry_track_id = empty($result->track_id) ? NULL : $result->track_id;
                    $inquiry_order_id = empty($result->order_id) ? NULL : $result->order_id;
                    $inquiry_amount = empty($result->amount) ? NULL : $result->amount;

                    if (empty($inquiry_status) || empty($inquiry_track_id) || empty($inquiry_amount) || $inquiry_amount != $amount || $inquiry_status != 100 || $inquiry_order_id !== $orderid) {
                        $response['result'] = 'پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.';
                        return false;
                    } else {
                        $this->paymentinfo['currency'] = 'usd';
                        $this->paymentinfo['amount'] = $cost[0][cost][usd];
                        $this->type = 1;
                        return true;
                    }
                } else {
                    $this->error = 'کاربر از انجام تراکنش منصرف شده است';
                    return false;
                }
            } else {
                $this->error = 'Invalid trasaction';
                return false;
            }
        } else {
            $this->error = 'Duplicate transaction.';
            return false;
        }
    }

    function test()
    {
        if (!empty($this->settings['api_key']) AND !empty($this->settings['currency_rate'])) {
            return true;
        }
        return false;
    }

    function generate_form_html($hash, $cost, $currency, $subinfo, $userinfo, $timeinfo)
    {
        global $vbphrase, $vbulletin, $show;

        $response['state'] = false;
        $response['result'] = "";

        $api_key = $this->settings['api_key'];
        $sandbox = $this->settings['sandbox'] == 1 ? 'true' : 'false';
        $amount = floor($cost * $this->settings['currency_rate']);

        if (!empty($this->getConfig('currency')) && $this->getConfig('currency') == 1) {
            $amount *= 10;
        }

        $desc = "خرید اشتراک توسط" . $userinfo['username'];
        $callback = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on' ? "https://" : "http://") . "://" . $_SERVER['HTTP_HOST'] . '/payment_gateway.php?method=idpay';

        if (empty($amount)) {
            return 'واحد پول انتخاب شده پشتیبانی نمی شود.';
        }

        $data = array(
            'order_id' => $hash,
            'amount' => $amount,
            'phone' => '',
            'desc' => $desc,
            'callback' => $callback,
        );
        $ch = curl_init('https://api.idpay.ir/v1/payment');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'X-API-KEY:' . $api_key,
            'X-SANDBOX:' . $sandbox,
        ));

        $result = curl_exec($ch);
        $result = json_decode($result);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_status != 201 || empty($result) || empty($result->id) || empty($result->link)) {
            return sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status);
        } else {
            $form['action'] = $result->link;
            $form['method'] = 'GET';
        }
        return $form;
    }
}