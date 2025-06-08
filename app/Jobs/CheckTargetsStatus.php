<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use App\Models\Target;
use App\Models\Status;
use App\Models\History;
use App\Models\Alert;
use Carbon\Carbon;

class CheckTargetsStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const HTTP_STATUS_MEANINGS = [
        200 => "OK",
        301 => "Moved Permanently",
        302 => "Found",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found",
        408 => "Request Timeout",
        500 => "Internal Server Error",
        502 => "Bad Gateway",
        503 => "Service Unavailable",
        504 => "Gateway Timeout",
    ];

    public function handle()
    {
        $targets = Target::all();

        foreach ($targets as $target) {
            try {
                $start = Carbon::now();

                $response = Http::withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36'
                ])->timeout(10)->get($target->url);

                $latency = $start->diffInMilliseconds(Carbon::now());

                // Record status
                Status::create([
                    'target_url_id' => $target->id,
                    'status_code' => $response->status(),
                    'latency_ms' => $latency,
                    'checked_at' => Carbon::now(),
                ]);

                History::create([
                    'target_url_id' => $target->id,
                    'status_code' => $response->status(),
                    'latency_ms' => $latency,
                    'checked_at' => Carbon::now(),
                ]);

                $statusDesc = self::HTTP_STATUS_MEANINGS[$response->status()] ?? 'Unknown Status';

                // Alert for error statuses
                if ($response->status() >= 400) {
                    $msg = "Target {$target->url} returned {$response->status()} ({$statusDesc}).";
                    Alert::create([
                        'target_url_id' => $target->id,
                        'message' => $msg
                    ]);
                    $this->sendAlertEmail($msg, $target);
                }

                // Check for two consecutive failures
                $recentStatuses = Status::where('target_url_id', $target->id)
                    ->orderBy('checked_at', 'desc')
                    ->take(2)
                    ->get();

                if ($recentStatuses->count() === 2 && $recentStatuses->every(function ($status) {
                    return $status->status_code >= 400;
                })) {
                    $msg = "Target {$target->url} returned errors for two consecutive checks.";
                    Alert::create([
                        'target_url_id' => $target->id,
                        'message' => $msg
                    ]);
                    $this->sendAlertEmail($msg, $target);
                }

            } catch (\Exception $e) {
                $msg = "Failed to check {$target->url}: {$e->getMessage()}";
                Alert::create([
                    'target_url_id' => $target->id,
                    'message' => $msg
                ]);
                $this->sendAlertEmail($msg, $target);
            }
        }
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
