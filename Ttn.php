<?php

namespace App\Models;

use App\Exceptions\DBConnectionException;
use App\Models\NovaPoshta\Printing\Forms\InternetDocumentsMargingsA4PrintForm;
use App\Models\NovaPoshta\Printing\Forms\InternetDocumentsMarkingsPrintForm;
use App\Models\NovaPoshta\Printing\Forms\InternetDocumentsPrintForm;
use App\Plugs\DeletedModelPlug;
use App\Plugs\ModelPlug;
use App\Services\Sms\TtnSmsService;
use App\Sms\Exception\ApiException;
use Carbon\Carbon;
use Delivery\NovaPoshta\InternetDocuments;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Ttn extends \App\Models\NovaPoshta\Printing\NovaPostaPrintModel
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ttn';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id_ttn';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'date_zakaz',
        'sum_predoplata',
        'cost_ttn',
        'zakaz_desc',
        'payer_type',
        'payment_method',
        'cargo_type',
        'volume',
        'weight',
        'kol_mest',
        'prim_ttn',
        'sender',
        'sender_city',
        'sender_address',
        'sender_contact',
        'sender_phone',
        'recipient_id_town',
        'recipient_fio',
        'recipient_phone',
        'recipient_id_wh',
        'date_otpravka',
        'is_redelivery',
        'redelivery_payer_type',
        'redelivery_sum',
    ];

    /**
     * @inheritDoc
     */
    protected $NovaPoshtaApiClass = InternetDocuments::class;

    /**
     * @inheritDoc
     */
    protected $printForms = [
        '0' => InternetDocumentsPrintForm::class,
        '1' => InternetDocumentsMarkingsPrintForm::class,
        '2' => InternetDocumentsMargingsA4PrintForm::class
    ];

    protected $senderList = null;

    protected $recipientList = null;

    public function getSenderList()
    {
        if (is_null($this->senderList)) {
            $this->senderList = [
                'sender'    => Counterparty::where('ref_counterparty', $this->sender)->first() ?? new DeletedModelPlug(),
                'contact'   => CounterpartyContactPerson::where('ref', $this->sender_contact)->first() ?? new DeletedModelPlug(),
                'city'      => Town::where('ref_town', $this->sender_city)->first(),
                'address'   => Wh::where('ref_wh', $this->sender_address)->first()
            ];
        }
        return $this->senderList;
    }

    public function getRecipientList()
    {
        if (is_null($this->recipientList)) {
            $this->recipientList = [
                'type'          => TypeCounterparty::where('ref_type_counterparty', $this->recipient_type)->first(),
                'city'          => Town::find($this->recipient_id_town),
                'wh'            => Wh::find($this->recipient_id_wh),
                'recipient'     => "{$this->recipient_fio}, \nтел. {$this->recipient_phone}"
            ];
        }
        return $this->recipientList;
    }

    public function getPayerType()
    {
        return PayerType::where('ref_payer_type', $this->payer_type)->first();
    }

    public function getPaymentMethod()
    {
        return PaymentForm::where('ref_payment_form', $this->payment_method)->first();
    }

    public function getPayerTypeRedelivery()
    {
        return PayerTypeRedelivery::where('ref_payer_type_redelivery', $this->redelivery_payer_type)->first();
    }

    /**
     * Get the register that owns the ttn.
     */
    public function register()
    {
        return $this->belongsTo(Register::class, 'id_register');
    }

    /**
     * @param bool $throwIgnore
     *
     * @return \App\Contracts\Sms\Sms|ModelPlug
     */
    public function findSms($throwIgnore = false)
    {
        try {
            return TtnSmsService::getManager()->find($this->id_sms);
        } catch (ApiException $e) {
            if ($throwIgnore) {
                return new ModelPlug();
            }
            throw new ApiException($e->getMessage(), $e->getCode());
        }
    }

    /**
     * @return bool
     */
    public function isExistSms(): bool
    {
        return ! is_null($this->id_sms) && ! is_null($this->findSms());
    }

    /**
     * Set the ttn date dostavka.
     *
     * @param  string  $value
     * @return void
     */
    public function setDateDostavkaAttribute($value)
    {
        $this->attributes['date_dostavka'] = (new Carbon($value))->isoFormat('YYYY-MM-DD');
    }

    public function checkIsIssue()
    {
        return (bool)$this->ref_ttn;
    }

    /**
     * @return array
     */
    protected function getInternetDocumentParams()
    {
        $recipient_town = Town::find($this->recipient_id_town);
        $data = [
            'NewAddress'            => 1,
            'PayerType'             => $this->payer_type,
            'PaymentMethod'         => $this->payment_method,
            'CargoType'             => $this->cargo_type,
            'VolumeGeneral'         => $this->volume,
            'Weight'                => $this->weight,
            'ServiceType'           => $this->service_type,
            'SeatsAmount'           => $this->kol_mest,
            'Description'           => $this->prim_ttn,
            'Cost'                  => $this->cost_ttn,
            'CitySender'            => $this->sender_city,
            'Sender'                => $this->sender,
            'SenderAddress'         => $this->sender_address,
            'ContactSender'         => $this->sender_contact,
            'SendersPhone'          => $this->sender_phone,
            'RecipientCityName'     => $recipient_town->town,
            'SettlementType'        => $recipient_town->type_town,
            'RecipientArea'         => $recipient_town->area->area,
            'RecipientAreaRegions'  => $recipient_town->area_region,
            'RecipientAddressName'  => Wh::find($this->recipient_id_wh)->num_wh,
            'RecipientHouse'        => '',
            'RecipientFlat'         => '',
            'RecipientName'         => $this->recipient_fio,
            'RecipientType'         => $this->recipient_type,
            'RecipientsPhone'       => $this->recipient_phone,
            'DateTime'              => (new Carbon($this->date_otpravka))->isoFormat('DD.MM.YYYY'),
        ];
        if ($this->is_redelivery) {
            $data['BackwardDeliveryData'] = [
                [
                    'PayerType'         => $this->redelivery_payer_type,
                    'CargoType'         => 'Money',
                    'RedeliveryString'  => $this->redelivery_sum
                ]
            ];
        }

        return $data;
    }

    public function internetDocumentSave()
    {
        if ($this->checkIsIssue()) {
            return [
                'success' => false,
                'errors' => [
                    __('ttn.issue-store-error-exists')
                ]
            ];
        }

        $result = $this->novaPoshtaApiExecute('save', $this->getInternetDocumentParams());

        if (!$result->getSuccess()) {
            return $result->getArray();
        }

        $data = $result->getData()[0];
        $this->ref_ttn          = $data['Ref'];
        $this->np_cost_ttn      = $data['CostOnSite'];
        $this->date_dostavka    = $data['EstimatedDeliveryDate'];
        $this->num_ttn          = $data['IntDocNumber'];
        $this->save();

        return $result->getArray();
    }

    public function internetDocumentUpdate()
    {
        if (!$this->checkIsIssue()) {
            return [
                'success' => false,
                'errors' => [
                    __('ttn.issue-update-error-doesnt-exists')
                ]
            ];
        }

        $params = $this->getInternetDocumentParams();
        $params['Ref'] = $this->ref_ttn;
        $result = $this->novaPoshtaApiExecute('update', $params);

        if (!$result->getSuccess()) {
            return $result->getArray();
        }

        $data = $result->getData()[0];
        $this->np_cost_ttn      = $data['CostOnSite'];
        $this->date_dostavka    = $data['EstimatedDeliveryDate'];
        $this->save();

        return $result->getArray();
    }

    public function internetDocumentDelete()
    {
        if (!$this->checkIsIssue()) {
            return [
                'success' => false,
                'errors' => [
                    __('ttn.issue-delete-error-doesnt-exists')
                ]
            ];
        }

        return $this->novaPoshtaApiExecute('delete', [ $this->ref_ttn ])->getArray();
    }

    /**
     * @inheritDoc
     */
    protected function getPrintModelRef(Model $model)
    {
        return $model->num_ttn;
    }

    /**
     * @param string | Builder $recipient_phone
     * @return Builder
     */
    public static function getTtnListNotReceived($recipient_phone)
    {
        if ($recipient_phone instanceof Builder) {
            $builder = $recipient_phone;
        } else {
            $builder = static::where('ttn.recipient_phone', '=', $recipient_phone);
        }
        return $builder
            ->where(function ($query) {
                $query
                    ->where(function ($query) {
                        // Если ТТН уже в пункте назначения
                        $query->whereIn('ttn.tracking_status_code', [ '7', '8' ])
                            // И прошло уже 3 дня или более после установки данного статуса
                            ->whereDate('ttn.tracking_status_edited_at', '<', Carbon::now()->subDays(3)->isoFormat('YYYY-MM-DD'));
                    })
                    ->orWhere('ttn.status_code', '=', 6);
            });
    }

    /**
     * Получить список ТТН, которые ожидают получения суммы обратной доставки.
     *
     * @param Builder|null $query
     * @return Builder
     */
    public static function getAwaitReceiptRedeliverySum(Builder $query = null): Builder
    {
        $query = $query ?? static::query();
        $query = clone $query;
        return $query
            ->where('ttn.tracking_status_code', 10)
            ->where('ttn.tracking_status_edited_at', '>', Carbon::now()->subDays(4)->isoFormat('YYYY-MM-DD'));
    }

    /**
     * Посчитать прибыль по ТТН запроса.
     *
     * @param Builder $query
     * @return int
     */
    public static function getSumEarnings(Builder $query): int
    {
        $query = clone $query;
        $query = $query->where('ttn.status_code', '=', 4);
        return $query->sum('ttn.sum_predoplata') + $query->sum('ttn.redelivery_sum');
    }

    /**
     * @param int $statusCode
     */
    public function setStatusCode($statusCode)
    {
        $this->status_code = $statusCode;
        $this->save();
    }

    public function setStatusCodeWithTrackingCode($trackingCode)
    {
        $status_code = null;

        switch ($trackingCode) {
            case 1:
                $status_code = 1;
                break;

            case 5:
            case 6:
            case 101:
            case 104:
                $status_code = 2;
                break;

            case 7:
            case 8:
            case 14:
                $status_code = 3;
                break;

            case 9:
            case 10:
            case 11:
            case 106:
                $status_code = 4;
                break;

            case 102:
            case 103:
            case 108:
                $status_code = 6;
                break;

            default:
                if (static::getTtnListNotReceived(static::where($this->getKeyName(), '=', $this->getKey()))->exists()) {
                    $status_code = 5;
                }
        }

        if (! is_null($status_code)) $this->setStatusCode($status_code);
    }

    /**
     * @return int
     */
    public function getStatusCode()
    {
        return $this->status_code;
    }

    /**
     * @return string
     */
    public function getStatusMsg()
    {
        return static::getStatusList()[$this->getStatusCode()];
    }

    /**
     * @return string[]
     */
    public static function getStatusList()
    {
        return [
            1 => 'Новый',
            2 => 'Отправлено (в пути)',
            3 => 'В отделении',
            4 => 'Получено',
            6 => 'Отказ',
            5 => 'Не получено'
        ];
    }
}
