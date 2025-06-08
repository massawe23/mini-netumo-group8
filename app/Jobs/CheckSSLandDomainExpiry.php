<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use App\Models\Target;
use App\Models\SSLCheck;
use App\Models\DomainCheck;
use App\Models\Alert;
use Carbon\Carbon;
use Iodev\Whois\Factory;

class CheckSSLandDomainExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        $targets = Target::all();
        $whois = Factory::get()->createWhois();

        foreach ($targets as $target) {
            try {
                $hostname = parse_url($target->url, PHP_URL_HOST);

                // SSL Check
                $sslInfo = $this->checkSSL($hostname);

                $sslCheck = SSLCheck::firstOrNew(['target_url_id' => $target->id]);
                $sslCheck->fill($sslInfo);
                $sslCheck->save();

                if ($sslInfo['days_to_expiry'] <= 14) {
                    $msg = "SSL certificate for {$target->url} expires in {$sslInfo['days_to_expiry']} days.";
                    Alert::create([
                        'target_url_id' => $target->id,
                        'message' => $msg
                    ]);
                    $this->sendAlertEmail($msg, $target);
                }

                // Domain Check
                $domainInfo = $this->checkDomainExpiry($whois, $hostname);

                $domainCheck = DomainCheck::firstOrNew(['target_url_id' => $target->id]);
                $domainCheck->fill($domainInfo);
                $domainCheck->save();

                if ($domainInfo['days_to_expiry'] !== null && $domainInfo['days_to_expiry'] <= 14) {
                    $msg = "Domain registration for {$target->url} expires in {$domainInfo['days_to_expiry']} days.";
                    Alert::create([
                        'target_url_id' => $target->id,
                        'message' => $msg
                    ]);
                    $this->sendAlertEmail($msg, $target);
                }

            } catch (\Exception $e) {
                $msg = "Failed SSL/domain check for {$target->url}: {$e->getMessage()}";
                Alert::create([
                    'target_url_id' => $target->id,
                    'message' => $msg
                ]);
                $this->sendAlertEmail($msg, $target);
            }
        }
    }

    private function checkSSL($hostname)
    {
        $context = stream_context_create([
            'ssl' => ['capture_peer_cert' => true]
        ]);

        $client = stream_socket_client(
            "ssl://{$hostname}:443",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        $cert = stream_context_get_params($client)['options']['ssl']['peer_cert'];
        $expiresAt = Carbon::createFromTimestamp($cert['validTo_time_t']);

        return [
            'expires_at' => $expiresAt,
            'days_to_expiry' => Carbon::now()->diffInDays($expiresAt)
        ];
    }

    private function checkDomainExpiry($whois, $domain)
    {
        $info = $whois->loadDomainInfo($domain);
        $expiresAt = $info->expirationDate ? Carbon::instance($info->expirationDate) : null;

        return [
            'expires_at' => $expiresAt,
            'days_to_expiry' => $expiresAt ? Carbon::now()->diffInDays($expiresAt) : null
        ];
    }

    private function sendAlertEmail($message, $target)
    {
        Mail::raw($message, function ($mail) use ($target) {
            $mail->to('rebecasamanda99@gmail.com')
                ->subject("[ALERT] Issue with {$target->url}")
                ->from('non-reply@gmail.com');
        });
    }
}
