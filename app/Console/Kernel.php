<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Laravel\Lumen\Console\Kernel as ConsoleKernel;
use App\Http\Controllers\TaskController;
use App\Helpers\Configs;
use App\Services\TelegramService;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:01.refresh_configs.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->refresh_configs();
            Configs::set('schedule:02.refresh_configs.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: refresh_configs" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Atualização de configurações!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();

        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:03.refresh_files_size.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->refresh_files_size();
            Configs::set('schedule:04.refresh_files_size.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: refresh_files_size" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Atualização de tamanho de arquivos no Banco de Dados!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();

        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:05.refresh_files_duration.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->refresh_files_duration();
            Configs::set('schedule:06.refresh_files_duration.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: refresh_files_duration" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Atualização de duração de arquivos no Banco de Dados!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();

        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:07.refresh_online_videos.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->refresh_online_videos();
            Configs::set('schedule:08.refresh_online_videos.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: refresh_files_duration" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Atualização de vídeos online!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();
        //})->daily();

        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:09.export_database.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->export_database();
            Configs::set('schedule:10.export_database.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: export_database" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Exportação de Banco de Dados!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();

        $schedule->call(function () {
            $controller = new TaskController();
            Configs::set('schedule:11.export_database_json.start', date('Y-m-d H:i:s'), 'datetime');
            $ret = $controller->export_database_json();
            Configs::set('schedule:12.export_database_json.end', date('Y-m-d H:i:s'), 'datetime', $ret);

            echo "Tarefa: export_database_json" . PHP_EOL;
            if ($ret) {
                echo "Executado!" . PHP_EOL;
                $telegramService = new TelegramService();
                $telegramService->sendMessage("⏰ Rotina executada: Exportação de Banco de Dados em JSON!");
                $telegramService->sendMessage("<pre>" . json_encode($ret, JSON_PRETTY_PRINT) . "</pre>");
            }
        })->hourly();

        //})->everyMinute();
    }
}
//php artisan schedule:run
