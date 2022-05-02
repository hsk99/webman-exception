<?php

namespace Hsk99\WebmanException;

use Webman\Bootstrap;
use Workerman\Timer;
use Throwable;
use support\Log;
use support\Container;
use PHPMailer\PHPMailer\PHPMailer;

class RunException implements Bootstrap
{
    /**
     * 进程ID
     *
     * @var int
     */
    protected static $_workerPid = null;

    /**
     * 进程名称
     *
     * @var string
     */
    protected static $_workerName = null;

    /**
     * 发送邮件缓存
     *
     * @var string
     */
    protected static $_mail = null;

    /**
     * 进程启动
     *
     * @author HSK
     * @date 2022-01-16 22:49:32
     *
     * @param \Workerman\Worker $worker
     *
     * @return void
     */
    public static function start($worker)
    {
        if ($worker) {
            if (\DIRECTORY_SEPARATOR !== '\\') {
                static::$_workerPid = @posix_getpid();
            }
            static::$_workerName = $worker->name;

            if (!config('plugin.hsk99.exception.app.notice', false)) {
                return;
            }

            $project = config('plugin.hsk99.exception.app.project', '');
            $email   = config('plugin.hsk99.exception.app.email', []);

            Timer::add(config('plugin.hsk99.exception.app.interval', 30), function () use (&$project, &$email) {
                if (0 === strlen(static::$_mail)) {
                    return;
                }

                static::sendEmail($email, $project . ' RunException', static::$_mail);
                static::$_mail = '';
            });
        }
    }

    /**
     * 记录
     *
     * @author HSK
     * @date 2022-01-16 22:31:24
     *
     * @param Throwable $exception
     *
     * @return void
     */
    public static function report(Throwable $exception)
    {
        $project = config('plugin.hsk99.exception.app.project', '');

        // 记录日志
        Log::warning($exception->getMessage(), [
            'worker'    => static::$_workerName,
            'exception' => (string)$exception,
        ]);

        if (config('plugin.hsk99.exception.app.debug', false)) {
            echo "\033[31;1m" . "[" . date('Y-m-d H:i:s') . "]\tWorker：" . static::$_workerName  . PHP_EOL . "\033[0m";
            echo $exception . PHP_EOL . PHP_EOL;
        }

        // 缓存邮件内容
        if (config('plugin.hsk99.exception.app.notice', false)) {
            try {
                static::$_mail .= '<p style="color:red;">Error time：'
                    . date('Y-m-d H:i:s')
                    . '</p><p style="color:red;">project：'
                    . $project
                    . '</p><p style="color:red;">WorkerName：'
                    . static::$_workerName
                    . '</p><p style="color:red;">WorkerPid：'
                    . static::$_workerPid
                    . '</p><br>'
                    . (string)$exception
                    . '<br><br><hr><br>';

                if (strlen(static::$_mail) > 51200) {
                    static::sendEmail(config('plugin.hsk99.exception.app.email', []), $project . ' RunException', static::$_mail);
                    static::$_mail = '';
                }
            } catch (\Throwable $th) {
                Log::error($th->getMessage(), ['exception' => (string)$th]);
            }
        }
    }

    /**
     * 发送邮件
     *
     * @author HSK
     * @date 2022-01-16 23:07:44
     *
     * @param string|array $toMail
     * @param string $subject
     * @param string $body
     * @param boolean $isHTML
     *
     * @return bool
     */
    protected static function sendEmail($toMail, $subject = '', $body = '', $isHTML = true): bool
    {
        try {
            $config = config('plugin.hsk99.exception.email');

            if (
                empty($config['smtp_host']) ||
                empty($config['smtp_user']) ||
                empty($config['smtp_pass']) ||
                empty($config['smtp_secure']) ||
                empty($config['smtp_port'])
            ) {
                throw new \Exception('未配置邮箱参数', 500);
            }

            if (!Container::has(PHPMailer::class)) {
                Container::make(PHPMailer::class, []);
            }
            /**
             * @var PHPMailer
             */
            $mail = Container::get(PHPMailer::class);

            $mail->CharSet    = "UTF-8";
            $mail->SMTPDebug  = 0;
            $mail->isSMTP();
            $mail->Host       = $config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $config['smtp_user'];
            $mail->Password   = $config['smtp_pass'];
            $mail->SMTPSecure = $config['smtp_secure'];
            $mail->Port       = $config['smtp_port'];
            $mail->From       = $config['smtp_user'];

            if (is_array($toMail)) {
                foreach ($toMail as $email) {
                    $mail->addAddress($email);
                }
            } else {
                $mail->addAddress($toMail);
            }

            $mail->isHTML($isHTML);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = $body;

            if ($mail->send()) {
                return true;
            } else {
                Log::error($mail->ErrorInfo);
                return false;
            }
        } catch (\Throwable $th) {
            Log::error($th->getMessage(), ['exception' => (string)$th]);
            return $th->getMessage();
        }
    }
}
