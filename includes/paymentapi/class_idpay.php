<?php
if (!isset($GLOBALS['vbulletin']->db)) exit;

class vB_PaidSubscriptionMethod_idpay extends vB_PaidSubscriptionMethod
{
    /**
     * @var bool
     */
    var $supports_recurring = false;

    /**
     * @var bool
     */
    var $display_feedback = true;

    /**
     * @param $hash
     * @param $cost
     * @param $currency
     * @param $subinfo
     * @param $userinfo
     * @param $timeinfo
     * @return array|bool|string
     */
    function generate_form_html($hash, $cost, $currency, $subinfo, $userinfo, $timeinfo)
    {
        global $vbphrase, $vbulletin, $show;

        $response['state'] = false;
        $response['result'] = "";

        $api_key = $this->settings['api_key'];
        $sandbox = $this->settings['sandbox'] == 1 ? 'true' : 'false';

        $amount = floor($cost * $this->settings['currency_rate']);

        $desc = "خرید اشتراک توسط" . $userinfo['username'];
        $callback = vB::$vbulletin->options['bburl'] . '/payment_gateway.php?method=idpay' ;

        if (empty($amount)) {
            echo 'واحد پول انتخاب شده پشتیبانی نمی شود.';
            return false;
        }

        $data = array(
            'order_id' => $hash,
            'amount' => $amount,
            'phone' => '',
            'desc' => $desc,
            'callback' => $callback,
            'name' => $userinfo['username'],
        );

        $ch = curl_init('https://api.idpay.ir/v1.1/payment');
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
            echo 'ERR: '. sprintf('خطا هنگام ایجاد تراکنش. کد خطا: %s', $http_status);

            return false;
        } else {
            $form['action'] = $result->link;
            $form['method'] = 'GET';
        }

        return $form;
    }

    /**
     * @return bool
     */
    function verify_payment()
    {

        $this->registry->input->clean_array_gpc('r', array(
            'id' => TYPE_STR,
            'status' => TYPE_STR,
            'order_id' => TYPE_STR,
            'track_id' => TYPE_STR,
        ));

        $pid = $this->registry->GPC['id'];
        $status = $this->registry->GPC['status'];
        $orderid = $this->registry->GPC['order_id'];
        $track_id = $this->registry->GPC['track_id'];

        if (!$this->test()) {
            $this->error = 'Payment processor not configured';
            return false;
        }

        $this->transaction_id = $track_id;

        if (!empty($orderid) && !empty($track_id) && !empty($pid) && !empty($status)) {

            $this->paymentinfo = $this->registry->db->query_first("SELECT paymentinfo.*, user.username FROM " . TABLE_PREFIX . "paymentinfo AS paymentinfo INNER JOIN " . TABLE_PREFIX . "user AS user USING (userid) WHERE hash = '" . $this->registry->db->escape_string($orderid) . "'");

            if ($status != 10) {
                $msg = $this->other_status_messages($status);
                $this->error = $msg;
                $this->error_code = $msg;
                $this->details = $msg;

                return false;
            }


            if (!empty($this->paymentinfo)) {
                $sub = $this->registry->db->query_first("SELECT * FROM " . TABLE_PREFIX . "subscription WHERE subscriptionid = " . $this->paymentinfo['subscriptionid']);

                $cost = unserialize($sub['cost']);
                $amount = floor($cost[0][cost][usd] * $this->settings['currency_rate']);

                if (!empty($pid) && !empty($orderid)) {
                    $api_key = $this->settings['api_key'];
                    $sandbox = $this->settings['sandbox'] == 1 ? 'true' : 'false';

                    $data = array(
                        'id' => $pid,
                        'order_id' => $orderid,
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, 'https://api.idpay.ir/v1.1/payment/verify');
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

                    if (empty($inquiry_status) || empty($inquiry_track_id) || empty($inquiry_amount) || $inquiry_status != 100 || $inquiry_order_id !== $orderid) {
                        $response['result'] = 'پرداخت شما ناموفق بوده است. لطفا مجددا تلاش نمایید یا در صورت بروز اشکال با مدیر سایت تماس بگیرید.';
                        return false;
                    } else {

                        $this->registry->input->clean_array_gpc('r', array(
                            'hashed_card_no'    => $result->payment->hashed_card_no,
                            'card_no'    => $result->payment->card_no,
                        ));

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
                $msg = $this->other_status_messages();
                $this->error_code = $msg;
                $this->error = $msg;

                return false;
            }
        } else {
            $msg = $this->other_status_messages();
            $this->error_code = $msg;
            $this->error = $msg;

            return false;
        }
    }

    /**
     * @return bool
     */
    function test()
    {
        if (!empty($this->settings['api_key']) AND !empty($this->settings['currency_rate'])) {
            return true;
        }
        return false;
    }

    /**
     * @param null $msgNumber
     * @return string
     */
    function other_status_messages($msgNumber = null)
    {

        switch ($msgNumber) {
            case "1":
                $msg = "پرداخت انجام نشده است";
                break;
            case "2":
                $msg = "پرداخت ناموفق بوده است";
                break;
            case "3":
                $msg = "خطا رخ داده است";
                break;
            case "4":
                $msg = "بلوکه شده";
                break;
            case "5":
                $msg = "برگشت به پرداخت کننده";
                break;
            case "6":
                $msg = "برگشت خورده سیستمی";
                break;
            case "7":
                $msg = "انصراف از پرداخت";
                break;
            case "8":
                $msg = "به درگاه پرداخت منتقل شد";
                break;
            case "10":
                $msg = "در انتظار تایید پرداخت";
                break;
            case "100":
                $msg = "پرداخت تایید شده است";
                break;
            case "101":
                $msg = "پرداخت قبلا تایید شده است";
                break;
            case "200":
                $msg = "به دریافت کننده واریز شد";
                break;
            case "0":
                $msg = "سواستفاده از تراکنش قبلی";
                break;
            case "404":
                $msg = "واحد پول انتخاب شده پشتیبانی نمی شود.";
                $msgNumber = '404';
                break;
            case null:
                $msg = "خطا دور از انتظار";
                $msgNumber = '1000';
                break;
        }

        return $msg . ' -وضعیت: ' . "$msgNumber";

    }

}
