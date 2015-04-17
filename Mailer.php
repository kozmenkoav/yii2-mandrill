<?php
/**
 * Contains the Mailer class.
 * 
 * @link http://www.creationgears.com/
 * @copyright Copyright (c) 2014 Nicola Puddu
 * @license http://www.gnu.org/copyleft/gpl.html
 * @package nickcv/yii2-mandrill
 * @author Nicola Puddu <n.puddu@outlook.com>
 */

namespace nickcv\mandrill;

use yii\mail\BaseMailer;
use yii\base\InvalidConfigException;
use nickcv\mandrill\Message;
use Mandrill;
use Mandrill_Error;

/**
 * Mailer is the class that consuming the Message object sends emails thorugh
 * the Mandrill API.
 *
 * @author Nicola Puddu <n.puddu@outlook.com>
 * @version 1.0
 */
class Mailer extends BaseMailer
{

    const STATUS_SENT = 'sent';
    const STATUS_QUEUED = 'queued';
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_REJECTED = 'rejected';
    const STATUS_INVALID = 'invalid';
    const LOG_CATEGORY = 'mandrill';

    /**
     * @var string Mandrill API key
     */
    private $_apikey;
    
    /**
     * Whether the mailer should check for mandrill templates before looking for
     * the view name.
     *
     * @var boolean use mandrill templates before looking for view files.
     * @since 1.2.0
     */
    public $useMandrillTemplates = false;

    /**
     * @var string message default class name.
     */
    public $messageClass = 'nickcv\mandrill\Message';

    /**
     * @var Mandrill the Mandrill instance
     */
    private $_mandrill;

    /**
     * Checks that the API key has indeed been set.
     * 
     * @inheritdoc
     */
    public function init()
    {
        if (!$this->_apikey) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" cannot be null.');
        }

        try {
            $this->_mandrill = new Mandrill($this->_apikey);
        } catch (\Exception $exc) {
            \Yii::error($exc->getMessage());
            throw new Exception('an error occurred with your mailer. Please check the application logs.', 500);
        }
    }

    /**
     * Sets the API key for Mandrill
     * 
     * @param string $apikey the Mandrill API key
     * @throws InvalidConfigException
     */
    public function setApikey($apikey)
    {
        if (!is_string($apikey)) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" should be a string, "' . gettype($apikey) . '" given.');
        }

        $trimmedApikey = trim($apikey);
        if (!strlen($trimmedApikey) > 0) {
            throw new InvalidConfigException('"' . get_class($this) . '::apikey" length should be greater than 0.');
        }

        $this->_apikey = $trimmedApikey;
    }
    
    /**
     * Composes the message using a Mandrill template if the useMandrillTemplates
     * settings is true.
     * 
     * If mandrill templates are not being used or if no template with the given
     * name has been found it will fallback to the normal compose method.
     * 
     * @inheritdoc
     * @since 1.2.0
     */
    public function compose($view = null, array $params = [], $async = false, $send_at = null) {
        if ($this->useMandrillTemplates) {
            try {
                $message           = parent::compose();
                $message->template = $view;
                $message->params   = $params;
                $message->async    = $async;
                $message->send_at  = $send_at;
                return $message;
            } catch (Mandrill_Error $e) {
                \Yii::info('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        }
        
        // fall back to rendering views
        return parent::compose($view, $params);
    }

    /**
     * Sends the specified message.
     * 
     * @param Message $message the message to be sent
     * @return boolean whether the message is sent successfully
     */
    protected function sendMessage($message)
    {
        $address = $address = implode(', ', $message->getTo());
        \Yii::info('Sending email "' . $message->getSubject() . '" to "' . $address . '"', self::LOG_CATEGORY);

        try {
            if ($this->useMandrillTemplates) {
                return $this->wasMessageSentSuccesfully($this->_mandrill->messages->sendTemplate($message->template, null, $message->getMandrillMessageArray(), $message->async, null, $message->send_at));
            } else {
                return $this->wasMessageSentSuccesfully($this->_mandrill->messages->send($message->getMandrillMessageArray()));
            }
        } catch (Mandrill_Error $e) {
            \Yii::error('A mandrill error occurred: ' . get_class($e) . ' - ' . $e->getMessage(), self::LOG_CATEGORY);
            return false;
        }
    }

    /**
     * parse the mandrill response and returns false if any message was either invalid or rejected
     * 
     * @param array $mandrillResponse
     * @return boolean
     */
    private function wasMessageSentSuccesfully($mandrillResponse)
    {
        $return = true;
        foreach ($mandrillResponse as $recipient) {
            switch ($recipient['status']) {
                case self::STATUS_INVALID:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has not been sent: status "' . $recipient['status'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_QUEUED:
                    \Yii::info('the email for "' . $recipient['email'] . '" is now in a queue waiting to be sent.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_REJECTED:
                    $return = false;
                    \Yii::warning('the email for "' . $recipient['email'] . '" has been rejected: reason "' . $recipient['reject_reason'] . '"', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SCHEDULED:
                    \Yii::info('the email submission for "' . $recipient['email'] . '" has been scheduled.', self::LOG_CATEGORY);
                    break;
                case self::STATUS_SENT:
                    \Yii::info('the email for "' . $recipient['email'] . '" has been sent.', self::LOG_CATEGORY);
                    break;
            }
        }

        return $return;
    }
    
    /**
     * Converts the parameters in the format used by Mandrill to render templates.
     * 
     * @param array $params
     * @return array
     * @since 1.2.0
     */
    private function getMergeParamsForMandrillTemplate($params) {
        $merge = [];
        foreach ($params as $key => $value) {
            $merge[] = ['name' => $key, 'content' => $value];
        }
        return $merge;
    }

}
