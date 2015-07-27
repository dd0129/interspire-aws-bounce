<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\App;

use Aglipanci\Interspire\Interspire;
use App\Services\Sqs;
use Log;

class BouncesController extends Controller
{

    /**
     * @var Interspire
     */
    private $interspire;

    /**
     * @var Sqs
     */
    private $sqs;

    /**
     * @var string|null
     */
    private $bouncesSqsUrl;

    /**
     * @param Sqs $sqs
     * @param Interspire $interspire
     */
    public function __construct(Sqs $sqs, Interspire $interspire)
    {
        $this->bouncesSqsUrl = env('BOUNCES_SQS_URL', null);

        if (is_null($this->bouncesSqsUrl))
            abort(403, 'BOUNCES_SQS_URL is not set in .env file');

        $this->interspire = $interspire;
        $this->sqs = $sqs;
    }

    /**
     * Process bounce queue messages
     */
    public function process()
    {
        $messages = $this->sqs->receiveMessages($this->bouncesSqsUrl);

        if (!is_null($messages))
            $this->handleMessages($messages);

        echo 'OK';
        exit; // we're done !
    }

    /**
     * @TODO handle loop better
     * @param $messages
     */
    private function handleMessages($messages)
    {
        foreach ($messages as $message) {

            $bounce = json_decode($message['Body']);

            switch ($bounce->bounce->bounceType) {

                // A transient bounce indicates that the recipient's ISP is not accepting messages for that
                // particular recipient at that time and you can retry delivery in the future.
                case "Transient" :
                    $this->manuallyReviewBounce($bounce);
                    break;

                // Remove all recipients that generated a permanent bounce or an unknown bounce.
                default:
                    foreach ($bounce->bounce->bouncedRecipients as $recipient) {
                        $email = $recipient->emailAddress;
                        $listids = $this->interspire->getAllListsForEmailAddress($email);

                        // if the email is not in any list, we skip
                        if (is_null($listids))
                            continue;

                        // CAREFUL !!! we want to bounce this email in ALL lists, you might want to change this
                        foreach ($listids as $listid) {
                            $this->bounceRecipient($email, $listid);
                        }
                    }
                    break;
            }

            // done with message so we delete it from queue
            $this->sqs->deleteMessage($this->bouncesSqsUrl, $message['ReceiptHandle']);
        }

        // kinda dirty loop ???
        $this->process();
    }

    /**
     * Log to review manually the bounce
     *
     * @TODO send email notifications or something
     * @param $bounce
     */
    private function manuallyReviewBounce($bounce)
    {
        Log::warning(json_encode($bounce));
    }


    /**
     * Mark recipient as bounced in mailing lists
     *
     * @param string $email
     * @param int $listid
     */
    private function bounceRecipient($email, $listid = 1)
    {
        $result = $this->interspire->bounceSubscriber($email, $listid);
        Log::info('BOUNCE // ' . $email . ' : ' . $result);
    }
}
