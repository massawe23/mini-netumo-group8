protected function schedule(Schedule $schedule)
{
    $schedule->job(new PingAllTargets())->everyFiveMinutes();
}
