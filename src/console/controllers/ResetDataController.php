<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\commerce\stripe\console\controllers;

use Craft;
use craft\commerce\console\Controller;
use craft\commerce\stripe\records\Customer as StripeCustomer;
use craft\commerce\stripe\records\Invoice;
use craft\commerce\stripe\records\PaymentIntent;
use craft\helpers\Console;
use Exception;
use yii\console\ExitCode;

/**
 * Allows you to reset Stripe plugin data.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.1
 */
class ResetDataController extends Controller
{
    /**
     * Reset Commerce data.
     */
    public function actionIndex(): int
    {
        $reset = $this->prompt('Resetting Stripe plugin data will permanently delete all customers, payment intents, and invoice records ... do you wish to continue?', [
            'required' => true,
            'default' => 'no',
            'validator' => function($input) {
                if (!in_array($input, ['yes', 'no'])) {
                    $this->stderr('You must answer either "yes" or "no".' . PHP_EOL, Console::FG_RED);
                    return false;
                }

                return true;
            },
        ]);

        if ($reset == 'yes') {
            $transaction = Craft::$app->getDb()->beginTransaction();

            try {
                $this->stdout('Resetting Stripe plugin data ...' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
                // if table exists
                if (Craft::$app->getDb()->tableExists(PaymentIntent::tableName())) {
                    Craft::$app->getDb()->createCommand()
                        ->delete(PaymentIntent::tableName())
                        ->execute();
                }
                if (Craft::$app->getDb()->tableExists(Invoice::tableName())) {
                    Craft::$app->getDb()->createCommand()
                        ->delete(Invoice::tableName())
                        ->execute();
                }
                if (Craft::$app->getDb()->tableExists(StripeCustomer::tableName())) {
                    Craft::$app->getDb()->createCommand()
                        ->delete(StripeCustomer::tableName())
                        ->execute();
                }

                $this->stdout('Finished.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);

                $transaction->commit();
            } catch (Exception $e) {
                $this->stdout($e->getmessage() . PHP_EOL, Console::FG_RED);
                $transaction->rollBack();
            }
        } else {
            $this->stdout('Skipping data reset.' . PHP_EOL . PHP_EOL, Console::FG_GREEN);
        }

        return ExitCode::OK;
    }
}
