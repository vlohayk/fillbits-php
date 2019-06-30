<?php
namespace Packages\Payments\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use FillbitsAPI;

/**
 * Fillbits.com API implementation
 *
 * Class FillbitsPaymentService
 */
class FillbitsPaymentService
{
    private $api;
    private $response;
    private $config;

    /**
     * FillbitsPaymentService constructor.
     */
    public function __construct()
    {
        $this->config = config('payments.fillbits');
        $this->api = new FillbitsAPI($this->config['public_key'], $this->config['username'], $this->config['password']);
    }

    /**
     * Get coins balances
     *
     * @return mixed
     */
    public function getBalances()
    {
        // cache balances for 5 mins
        return Cache::remember('balances', 5, function() {
            $this->response = $this->api->GetCoinBalances();
            $result = $this->requestIsSuccessful() ? $this->response['result'] : [];
            return collect($result);
        });
    }

    /**
     * Get withdrawal info
     *
     * @param $id
     * @return \Illuminate\Support\Collection
     */
    public function getWithdrawalInfo($id)
    {
        return Cache::remember('widthdrawal_info_' . $id, 1, function() use($id) {
            $this->response = $this->api->GetWithdrawalInformation($id);
            $result = $this->requestIsSuccessful() ? $this->response['result'] : [];
            return collect($result);
        });
    }

    /**
     * @param $amount
     * @param $paymentCurrency
     * @param $deposit_address
     * @param $transaction_id
     * @param $expiration
     * @return array
     * @throws \Exception
     */
    public function initializePayment($amount, $paymentCurrency, $deposit_address, $transaction_id, $expiration)
    {
        $this->response = $this->api->CreateComplexTransaction(
            $amount,
            $paymentCurrency,
            $deposit_address,
            $transaction_id,
            route('webhook.deposits.ipn'),
            $expiration
        );
        Log::info($this->response);
        if (!$this->requestIsSuccessful())
            throw new \Exception($this->response['error']);
        $response = array(
            'external_id' => $this->response['id'],
            'wallet_id' => $this->response['wallets'][0]['id'],
            'wallet_address' => $this->response['wallets'][0]['address']
        );
        return $response;
    }

    public function checkPaymentStatus($transaction_id)
    {
        $this->response = $this->api->GetPaymentInfo($transaction_id);
        Log::info($this->response);
        if (!$this->requestIsSuccessful())
            throw new \Exception($this->response['error']);
        return $this->response;
    }

    public function initializeWithdrawal($amount, $currency, $paymentCurrency, $address, $note)
    {
        $this->response = $this->api->CreateWithdrawal([
            'amount'        => $amount,
            'currency'      => $paymentCurrency,
            'currency2'     => $currency,
            'address'       => $address,
            'auto_confirm'  => 1, // If set to 1, withdrawal will complete without email confirmation.
            'note'          => $note,
            'ipn_url'       => route('webhook.withdrawals.ipn'),
        ]);

        Log::info($this->response);

        if (!$this->requestIsSuccessful())
            throw new \Exception($this->response['error']);
    }

    private function requestIsSuccessful() {
        return $this->response && empty($this->response['error']);
    }

    /**
     * Get accepted coins symbols (they are set up in the fillbits account)
     *
     * @return Collection
     */
    public function getAcceptedCurrencies() {
        $currencies = Cache::get('fillbits-accepted-currencies');
        // if cached values don't exist
        if (!$currencies || empty($currencies)) {
            $this->response = $this->api->GetRatesWithAccepted();
            // currencies retrieved
            if ($this->requestIsSuccessful()) {
                // get accepted currencies symbols
                $currencies = collect($this->response)
                    ->filter(function ($currency, $symbol) {
                        return true;
                        return $currency['is_fiat'] == 0 && $currency['status'] == 'online' && $currency['accepted'] == 1;
                    })
                    ->sortBy('name');

                // store them in cache
                if (!$currencies->isEmpty()) {
                    Cache::put('fillbits-accepted-currencies', $currencies, 1440/* 24 hours */);
                }
            }
        }

        return $currencies ?: collect();
    }

    /**
     * Get payment amount
     *
     * @return number
     */
    public function getPaymentAmount()
    {
        return $this->requestIsSuccessful() ? $this->response['result']['amount'] : NULL;
    }


    public function redirectToGateway()
    {
        if ($this->requestIsSuccessful()) {
            header('Location: ' . $this->response['result']['status_url']);
            exit;
        }
    }

    public function getPaymentId()
    {
        return $this->requestIsSuccessful() ? $this->response['result']['txn_id'] : NULL;
    }

    public function getWithdrawalId()
    {
        return $this->requestIsSuccessful() ? $this->response['result']['id'] : NULL;
    }

    /**
     * Check whether IPN callback has a valid signature
     *
     * @param $content
     * @param $header
     * @return bool
     */
    public function signatureIsValid($content, $header)
    {
        $hmac = hash_hmac('sha512', $content, $this->config['secret_key']);
        return hash_equals($hmac, $header);
    }
}