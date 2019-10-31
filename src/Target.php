<?php

namespace gonimar\sentry;

use Sentry\Options;
use Sentry\Severity;
use Sentry\State\Scope;
use Throwable;
use Yii;
use yii\helpers\ArrayHelper;
use yii\helpers\VarDumper;
use yii\log\Logger;
use yii\log\Target as BaseTarget;
use function Sentry\captureEvent;
use function Sentry\captureException;
use function Sentry\captureMessage;
use function Sentry\configureScope;
use function Sentry\init;

/**
 * Sentry log target for a Sentry.
 *
 * @see https://docs.sentry.io/error-reporting/quickstart/?platform=php
 */
class Target extends BaseTarget
{
    /**
     * @var string Sentry DSN.
     *
     * @see https://docs.sentry.io/platforms/php/#connecting-the-sdk-to-sentry
     */
    public $dsn;

    /**
     * @var bool Write the context.
     */
    public $context = true;

    /**
     * @var array
     * @see Options::configureOptions()
     */
    protected $options;

    /**
     * @var array fields from `Yii::$app->user->getIdentity` store to Sentry user.
     * Default store user id and user ip fields
     * ['email', 'username']
     */
    public $userIdentityFields = [];

    /**
     * @inheritdoc
     */
    public function init()
    {
        init(ArrayHelper::merge($this->options, [
            'dsn' => $this->dsn,
            'environment' => YII_ENV,
            'release' => Yii::$app->id . '@' . Yii::$app->version,
        ]));
    }

    /**
     * @inheritdoc
     */
    protected function getContextMessage()
    {
        return '';
    }

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function export()
    {
        foreach ($this->messages as $message) {
            list($text, $level, $category, $timestamp, $traces) = $message;

            $data = [
                'level' => static::getLevelName($level),
                'timestamp' => $timestamp,
                'tags' => [
                    'category' => $category,
                    'page_locale' => Yii::$app->language,
                    'module' => $this->getModulePath()
                ],
                'extra' => [],
            ];

            $user = [
                'id' => null,
                //'username' => null,
                //'email' => null,
                'ip_address' => Yii::$app->request->userIP,
            ];

            if ($identity = Yii::$app->user->getIdentity(false)) {
                $user['id'] = $identity->getId();

                foreach ($this->userIdentityFields as $userIdentityField) {
                    $user[$userIdentityField] = $identity->$userIdentityField;
                }
            }

            configureScope(function (Scope $scope) use ($user): void {
                $scope->setUser($user);
            });

            if ($text instanceof Throwable || $text instanceof \Exception) {
                captureException($text);
                captureEvent($data);
                continue;
            } elseif (is_array($text)) {
                if (isset($text['msg'])) {
                    $data['message'] = $text['msg'];
                    unset($text['msg']);
                    $data['extra']['data'] = $text;
                } else if (empty($data['message'])) {
                    $data['message'] = VarDumper::export($text);
                }

                if (isset($text['tags'])) {
                    $data['tags'] = ArrayHelper::merge($data['tags'], $text['tags']);
                    unset($text['tags']);
                }

                configureScope(function (Scope $scope) use ($data): void {
                    $scope->setTags($data['tags']);
                });

                $data['extra']['data'] = $text;
            } else {
                $data['message'] = (string) $text;
            }

            if ($this->context) {
                $data['extra']['context'] = parent::getContextMessage();
            }

            captureMessage($data['message'], $data['level']);
            captureEvent($data);
        }
    }

    /**
     * @param integer $level
     * @return Severity
     */
    public static function getLevelName($level)
    {
        switch ($level) {
            case Logger::LEVEL_WARNING:
                return Severity::warning();
            case Logger::LEVEL_INFO:
                return Severity::info();
            case Logger::LEVEL_TRACE:
            case Logger::LEVEL_PROFILE:
            case Logger::LEVEL_PROFILE_BEGIN:
            case Logger::LEVEL_PROFILE_END:
                return Severity::debug();
            case Logger::LEVEL_ERROR:
            default:
                return Severity::error();
        }
    }

    /**
     * @return string|null
     */
    private function getModulePath()
    {
        if (!Yii::$app->controller) {
            return null;
        }

        $moduleId = '';
        $module = Yii::$app->controller->module;

        while ($module !== null) {
            $moduleId = $module->id . '/' . $moduleId;
            $module = $module->module;
        }

        return trim($moduleId, '/');
    }
}
