<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{

    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * BookingRepository constructor.
     *
     * @param Job               $model
     * @param MailerInterface   $mailer
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->setupLogger();

    /**
     * Set up the logger with appropriate handlers.
     */
    private function setupLogger()
    {
        $this->logger = new Logger('admin_logger');

        $logPath = storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log');
        $this->logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        // Find the user by ID
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            // Check user type and fetch jobs accordingly
            if ($cuser->is('customer')) {
                // Eager load relationships and filter jobs
                $jobs = $cuser->jobs()
                    ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                    ->whereIn('status', ['pending', 'assigned', 'started'])
                    ->orderBy('due', 'asc')
                    ->get();
                $usertype = 'customer';
            } elseif ($cuser->is('translator')) {
                // Fetch translator jobs and extract 'jobs' field
                $jobs = Job::getTranslatorJobs($cuser->id, 'new');
                $jobs = $jobs->pluck('jobs')->all();
                $usertype = 'translator';
            }

            if ($jobs) {
                // Categorize jobs into emergency and normal based on 'immediate'
                foreach ($jobs as $jobitem) {
                    if ($jobitem->immediate == 'yes') {
                        $emergencyJobs[] = $jobitem;
                    } else {
                        $normalJobs[] = $jobitem;
                    }
                }

                // Update 'normalJobs' array with user check information
                $normalJobs = collect($normalJobs)->each(function ($item, $key) use ($user_id) {
                    $item['usercheck'] = Job::checkParticularJob($user_id, $item);
                })->sortBy('due')->all();
            }
        }

        // Return the result as an associative array
        return [
            'emergencyJobs' => $emergencyJobs,
            'normalJobs' => $normalJobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }

    /**
     * @param int      $user_id
     * @param Request  $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        // Get page from the request or default to 1
        $pagenum = $request->get('page', 1);

        // Find the user by ID
        $cuser = User::find($user_id);
        $usertype = '';
        $emergencyJobs = [];
        $normalJobs = [];

        if ($cuser) {
            if ($cuser->is('customer')) {
                // Fetch customer jobs with specific statuses and paginate
                $jobs = $cuser->jobs()->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                    ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                    ->orderBy('due', 'desc')
                    ->paginate(15);

                $usertype = 'customer';
                return [
                    'emergencyJobs' => $emergencyJobs,
                    'noramlJobs' => [],
                    'jobs' => $jobs,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => 0,
                    'pagenum' => 0
                ];
            } elseif ($cuser->is('translator')) {
                // Fetch translator jobs with pagination
                $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
                $totaljobs = $jobs_ids->total();
                $numpages = ceil($totaljobs / 15);

                $usertype = 'translator';

                // Assign jobs and normalJobs with the same data
                $jobs = $noramlJobs = $jobs_ids;

                return [
                    'emergencyJobs' => $emergencyJobs,
                    'noramlJobs' => $noramlJobs,
                    'jobs' => $jobs,
                    'cuser' => $cuser,
                    'usertype' => $usertype,
                    'numpages' => $numpages,
                    'pagenum' => $pagenum
                ];
            }
        }
    }
    /**
     * @param User $user
     * @param array $data
     * @return array
     */
    // changing store function into smaller functions
    public function store(User $user, array $data)
    {
        // Set default immediate time
        $immediateTime = 5;
        $consumerType = $user->userMeta->consumer_type;

        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            return [
                'status' => 'fail',
                'message' => 'Translator cannot create booking',
            ];
        }

        $cuser = $user;

        // Validation for required fields
        $requiredFields = ['from_language_id', 'duration'];
        if ($data['immediate'] == 'no') {
            $requiredFields = array_merge($requiredFields, ['due_date', 'due_time', 'customer_phone_type', 'duration']);
        }
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || $data[$field] == '') {
                return [
                    'status' => 'fail',
                    'message' => 'Du måste fylla in alla fält',
                    'field_name' => $field,
                ];
            }
        }

        // Set customer_phone_type based on checkbox
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';

        // Set customer_physical_type based on checkbox
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';

        // Set due date based on immediate or regular booking
        if ($data['immediate'] == 'yes') {
            $dueCarbon = Carbon::now()->addMinute($immediateTime);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
            $data['immediate'] = 'yes';
            $data['customer_phone_type'] = 'yes';

            $response['type'] = 'immediate';
        } else {
            $due = $data['due_date'] . " " . $data['due_time'];
            $response['type'] = 'regular';
            $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $due);
            $data['due'] = $dueCarbon->format('Y-m-d H:i:s');

            if ($dueCarbon->isPast()) {
                return [
                    'status' => 'fail',
                    'message' => "Can't create booking in the past",
                ];
            }
        }

        // Set certified and gender based on job_for array
        $this->setCertifiedAndGender($data);

        // Set job type based on consumer type
        $this->setJobType($data, $consumerType);

        // Set additional fields
        $data['b_created_at'] = date('Y-m-d H:i:s');
        if (isset($due)) {
            $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        }
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        // Create job and return success response
        $job = $cuser->jobs()->create($data);

        return [
            'status' => 'success',
            'id' => $job->id,
            'job_for' => $this->getJobForArray($job),
            'customer_town' => $cuser->userMeta->city,
            'customer_type' => $cuser->userMeta->customer_type,
        ];
    }

    /**
     * Set certified and gender based on job_for array
     * @param array $data
     */
    private function setCertifiedAndGender(array &$data)
    {
        // Set certified based on job_for array
        $certifiedOptions = ['certified', 'certified_in_law', 'certified_in_helth'];
        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = 'normal';
        } elseif (in_array('certified', $data['job_for'])) {
            $data['certified'] = 'yes';
        } elseif (count(array_intersect($certifiedOptions, $data['job_for'])) > 1) {
            $data['certified'] = 'both';
        } else {
            $data['certified'] = reset($data['job_for']);
        }

        // Set gender based on job_for array
        if (in_array('male', $data['job_for'])) {
            $data['gender'] = 'male';
        } elseif (in_array('female', $data['job_for'])) {
            $data['gender'] = 'female';
        }
    }

    /**
     * Set job type based on consumer type
     * @param array $data
     * @param string $consumerType
     */
    private function setJobType(array &$data, string $consumerType)
    {
        switch ($consumerType) {
            case 'rwsconsumer':
                $data['job_type'] = 'rws';
                break;
            case 'ngo':
                $data['job_type'] = 'unpaid';
                break;
            case 'paid':
                $data['job_type'] = 'paid';
                break;
        }
    }

    /**
     * Get job_for array based on job properties
     * @param Job $job
     * @return array
     */
    private function getJobForArray(Job $job)
    {
        $jobFor = [];
        
        if ($job->gender != null) {
            $jobFor[] = $job->gender == 'male' ? 'Man' : 'Kvinna';
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $jobFor[] = 'normal';
                $jobFor[] = 'certified';
            } else {
                $jobFor[] = $job->certified;
            }
        }

        return $jobFor;
    }
    /**
     * Store job email and send confirmation email.
     *
     * @param array $data
     * @return mixed
     */
    public function storeJobEmail(array $data)
    {
        $userType = $data['user_type'];

        // Find the job by ID
        $job = Job::findOrFail($data['user_email_job_id']);
        $job->user_email = $data['user_email'] ?? '';
        $job->reference = $data['reference'] ?? '';

        // Retrieve user associated with the job
        $user = $job->user()->first();

        // Update job details based on provided address information or fallback to user's information
        if (isset($data['address'])) {
            $job->address = $data['address'] != '' ? $data['address'] : $user->userMeta->address;
            $job->instructions = $data['instructions'] != '' ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = $data['town'] != '' ? $data['town'] : $user->userMeta->city;
        }

        // Save the updated job details
        $job->save();

        // Determine email and name based on user_email or user's information
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        // Email subject
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        // Data to be sent with the email
        $sendData = [
            'user' => $user,
            'job' => $job
        ];

        // Send confirmation email
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $sendData);

        // Prepare the response
        $response = [
            'type' => $userType,
            'job' => $job,
            'status' => 'success'
        ];

        // Convert job data for Push notification
        $pushData = $this->jobToData($job);

        // Fire the JobWasCreated event
        Event::fire(new JobWasCreated($job, $pushData, '*'));

        return $response;
    }

    /**
     * Convert job details to an array for Push notification.
     *
     * @param Job $job
     * @return array
     */
    public function jobToData(Job $job)
    {
        // Extract necessary job information
        $data = [
            'job_id' => $job->id,
            'from_language_id' => $job->from_language_id,
            'immediate' => $job->immediate,
            'duration' => $job->duration,
            'status' => $job->status,
            'gender' => $job->gender,
            'certified' => $job->certified,
            'due' => $job->due,
            'job_type' => $job->job_type,
            'customer_phone_type' => $job->customer_phone_type,
            'customer_physical_type' => $job->customer_physical_type,
            'customer_town' => $job->town,
            'customer_type' => $job->user->userMeta->customer_type
        ];

        // Extract due date and time
        $dueDate = explode(" ", $job->due);
        $data['due_date'] = $dueDate[0];
        $data['due_time'] = $dueDate[1];

        // Extract job_for information
        $data['job_for'] = $this->getJobForArray($job);

        return $data;
    }

    /**
     * Perform necessary actions when ending a job session.
     *
     * @param array $postData
     */
    public function jobEnd(array $postData = [])
    {
        // Get completed date and job ID from the post data
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];

        // Find the job with translatorJobRel relationship
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
        $dueDate = $jobDetail->due;

        // Calculate session time
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        // Update job details
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        // Retrieve user associated with the job
        $user = $job->user()->first();

        // Determine email based on user_email or user's information
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;

        // Email subject
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        // Extract session time in hours and minutes
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';

        // Data to be sent with the email
        $data = [
            'user' => $user,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'faktura'
        ];

        // Send email to the user
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Save the updated job details
        $job->save();

        // Find the translator job relationship
        $translatorJobRel = $job->translatorJobRel
            ->where('completed_at', Null)
            ->where('cancel_at', Null)
            ->first();

        // Fire the SessionEnded event
        Event::fire(new SessionEnded($job, ($postData['userid'] == $job->user_id) ? $translatorJobRel->user_id : $job->user_id));

        // Retrieve user associated with the translator job relationship
        $translatorUser = $translatorJobRel->user()->first();

        // Email subject for translator
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

        // Data to be sent with the email for translator
        $data = [
            'user' => $translatorUser,
            'job' => $job,
            'session_time' => $sessionTime,
            'for_text' => 'lön'
        ];

        // Send email to the translator
        $mailer = new AppMailer();
        $mailer->send($translatorUser->email, $translatorUser->name, $subject, 'emails.session-ended', $data);

        // Update translator job relationship details
        $translatorJobRel->completed_at = $completedDate;
        $translatorJobRel->completed_by = $postData['userid'];
        $translatorJobRel->save();
    }
    
    /**
     * Get potential job IDs for a user based on translator type and other criteria.
     *
     * @param int $userId
     * @return array
     */
    public function getPotentialJobIdsWithUserId(int $userId): array
    {
        $userMeta = UserMeta::where('user_id', $userId)->first();
        $translatorType = $userMeta->translator_type;
        $jobType = ($translatorType == 'professional') ? 'paid' : (($translatorType == 'rwstranslator') ? 'rws' : 'unpaid');

        $languages = UserLanguages::where('user_id', $userId)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        $jobIds = Job::getJobs($userId, $jobType, 'pending', $languages, $gender, $translatorLevel);

        foreach ($jobIds as $key => $value) {
            $job = Job::find($value->id);
            $jobUserId = $job->user_id;
            $checkTown = Job::checkTowns($jobUserId, $userId);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') &&
                $job->customer_physical_type == 'yes' && $checkTown == false
            ) {
                unset($jobIds[$key]);
            }
        }

        return TeHelper::convertJobIdsInObjs($jobIds);
    }

    /**
     * Send push notifications to suitable translators based on job criteria.
     *
     * @param Job $job
     * @param array $data
     * @param int $excludeUserId
     */
    public function sendNotificationTranslator(Job $job, array $data = [], int $excludeUserId)
    {
        $users = User::all();
        $translatorArray = []; // Suitable translators (no need to delay push)
        $delayPayTranslatorArray = []; // Suitable translators (need to delay push)

        foreach ($users as $oneUser) {
            if ($oneUser->user_type == '2' && $oneUser->status == '1' && $oneUser->id != $excludeUserId) {
                if (!$this->isNeedToSendPush($oneUser->id)) {
                    continue;
                }

                $notGetEmergency = TeHelper::getUsermeta($oneUser->id, 'not_get_emergency');
                if ($data['immediate'] == 'yes' && $notGetEmergency == 'yes') {
                    continue;
                }

                $jobs = $this->getPotentialJobIdsWithUserId($oneUser->id);

                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) {
                        $userId = $oneUser->id;
                        $jobForTranslator = Job::assignedToPaticularTranslator($userId, $oneJob->id);

                        if ($jobForTranslator == 'SpecificJob') {
                            $jobChecker = Job::checkParticularJob($userId, $oneJob);

                            if ($jobChecker != 'userCanNotAcceptJob') {
                                if ($this->isNeedToDelayPush($oneUser->id)) {
                                    $delayPayTranslatorArray[] = $oneUser;
                                } else {
                                    $translatorArray[] = $oneUser;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';

        $msgContents = ($data['immediate'] == 'no')
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due']
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';

        $msgText = [
            "en" => $msgContents
        ];

        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translatorArray, $delayPayTranslatorArray, $msgText, $data]);

        $this->sendPushNotificationToSpecificUsers($translatorArray, $job->id, $data, $msgText, false);
        $this->sendPushNotificationToSpecificUsers($delayPayTranslatorArray, $job->id, $data, $msgText, true);
    }

    /**
     * Send SMS notifications to translators and return the count of translators.
     *
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator(Job $job): int
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = UserMeta::where('user_id', $job->user_id)->first();

        // Prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = $this->convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);
        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        // Analyze whether it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') {
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes') {
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        } elseif ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes') {
            // It's both, but should be handled as a phone job
            $message = $phoneJobMessageTemplate;
        } else {
            // This shouldn't be feasible, so no handling of this edge case
            $message = '';
        }

        Log::info($message);

        // Send messages via SMS handler
        foreach ($translators as $translator) {
            // Send message to translator
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Check if a push notification needs to be delayed.
     *
     * @param int $userId
     * @return bool
     */
    public function isNeedToDelayPush(int $userId): bool
    {
        if (!DateTimeHelper::isNightTime()) {
            return false;
        }

        $notGetNightTime = TeHelper::getUsermeta($userId, 'not_get_nighttime');
        return $notGetNightTime == 'yes';
    }

    /**
     * Check if a push notification needs to be sent.
     *
     * @param int $userId
     * @return bool
     */
    public function isNeedToSendPush(int $userId): bool
    {
        $notGetNotification = TeHelper::getUsermeta($userId, 'not_get_notification');
        return $notGetNotification != 'yes';
    }

    /**
     * Send OneSignal push notifications with User-Tags.
     *
     * @param array $users
     * @param int $jobId
     * @param array $data
     * @param array $msgText
     * @param bool $isNeedDelay
     */
    public function sendPushNotificationToSpecificUsers(array $users, int $jobId, array $data, array $msgText, bool $isNeedDelay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $jobId, [$users, $data, $msgText, $isNeedDelay]);

        if (env('APP_ENV') == 'prod') {
            $onesignalAppID = config('app.prodOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey'));
        } else {
            $onesignalAppID = config('app.devOnesignalAppID');
            $onesignalRestAuthKey = sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        }

        $userTags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $jobId;
        $iosSound = 'default';
        $androidSound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            if ($data['immediate'] == 'no') {
                $androidSound = 'normal_booking';
                $iosSound = 'normal_booking.mp3';
            } else {
                $androidSound = 'emergency_booking';
                $iosSound = 'emergency_booking.mp3';
            }
        }

        $fields = [
            'app_id' => $onesignalAppID,
            'tags' => json_decode($userTags),
            'data' => $data,
            'title' => ['en' => 'DigitalTolk'],
            'contents' => $msgText,
            'ios_badgeType' => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound' => $androidSound,
            'ios_sound' => $iosSound
        ];

        if ($isNeedDelay) {
            $nextBusinessTime = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $nextBusinessTime;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', $onesignalRestAuthKey]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        $response = curl_exec($ch);
        $logger->addInfo('Push send for job ' . $jobId . ' curl answer', [$response]);
        curl_close($ch);
    }

    /**
     * Get user tags string from an array of users.
     *
     * @param array $users
     * @return string
     */
    private function getUserTagsStringFromArray(array $users): string
    {
        return implode(',', array_map(function ($user) {
            return 'user_' . $user->id;
        }, $users));
    }

    /**
     * Convert minutes to hours and minutes.
     *
     * @param int $minutes
     * @return string
     */
    private function convertToHoursMins(int $minutes): string
    {
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        return ($hours > 0 ? $hours . ' tim ' : '') . ($remainingMinutes > 0 ? $remainingMinutes . ' min' : '');
    }

    /**
     * Get potential translators based on job criteria.
     *
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {
        // Map job type to translator type
        $translatorTypeMap = [
            'paid' => 'professional',
            'rws' => 'rwstranslator',
            'unpaid' => 'volunteer',
        ];

        // Default to null if job type is not recognized
        $translatorType = $translatorTypeMap[$job->job_type] ?? null;

        if ($translatorType === null) {
            // Handle invalid job types
            return [];
        }

        // Extract job details
        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;
        $translatorLevels = $this->getTranslatorLevels($job);

        // Fetch blacklisted translators
        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $blacklistedTranslators = collect($blacklist)->pluck('translator_id')->all();

        // Get potential translators based on criteria
        $potentialTranslators = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevels, $blacklistedTranslators);

        return $potentialTranslators;
    }

    /**
     * Get translator levels based on job certification.
     *
     * @param Job $job
     * @return array
     */
    private function getTranslatorLevels(Job $job): array
    {
        $translatorLevels = [];

        if (!empty($job->certified)) {
            switch ($job->certified) {
                case 'yes':
                case 'both':
                    $translatorLevels[] = 'Certified';
                    $translatorLevels[] = 'Certified with specialisation in law';
                    $translatorLevels[] = 'Certified with specialisation in health care';
                    break;
                case 'law':
                case 'n_law':
                    $translatorLevels[] = 'Certified with specialisation in law';
                    break;
                case 'health':
                case 'n_health':
                    $translatorLevels[] = 'Certified with specialisation in health care';
                    break;
                case 'normal':
                    $translatorLevels[] = 'Layman';
                    $translatorLevels[] = 'Read Translation courses';
                    break;
                case null:
                    $translatorLevels[] = 'Certified';
                    $translatorLevels[] = 'Certified with specialisation in law';
                    $translatorLevels[] = 'Certified with specialisation in health care';
                    $translatorLevels[] = 'Layman';
                    $translatorLevels[] = 'Read Translation courses';
                    break;
            }
        }

        return $translatorLevels;
    }

    /**
     * Update a job with the provided data.
     *
     * @param int $id
     * @param array $data
     * @param User $cuser
     * @return array
     */
    public function updateJob(int $id, array $data, User $cuser): array
    {
        // Retrieve the job by ID
        $job = Job::find($id);

        // Find the current or completed translator for the job
        $currentTranslator = $job->translatorJobRel->where('cancel_at', null)->first();
        if (is_null($currentTranslator)) {
            $currentTranslator = $job->translatorJobRel->where('completed_at', '!=', null)->first();
        }

        // Initialize log data array
        $logData = [];

        // Check if translator has changed and update log data
        $changeTranslator = $this->changeTranslator($currentTranslator, $data, $job);
        if ($changeTranslator['translatorChanged']) {
            $logData[] = $changeTranslator['log_data'];
        }

        // Check if due date has changed and update log data
        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $oldTime = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        // Check if source language has changed and update log data
        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id']),
            ];
            $oldLang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        // Check if job status has changed and update log data
        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged']) {
            $logData[] = $changeStatus['log_data'];
        }

        // Update admin comments
        $job->admin_comments = $data['admin_comments'];

        // Log the job update
        $this->logger->addInfo(
            'USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data: ',
            $logData
        );

        // Update job reference
        $job->reference = $data['reference'];

        // Save the job changes
        $job->save();

        // Handle additional notifications if applicable
        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) {
                $this->sendChangedDateNotification($job, $oldTime);
            }
            if ($changeTranslator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $currentTranslator, $changeTranslator['new_translator']);
            }
            if ($langChanged) {
                $this->sendChangedLangNotification($job, $oldLang);
            }
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $old_status = $job->status;
        $statusChanged = false;
        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $statusChanged = $this->changeTimedoutStatus($job, $data, $changedTranslator);
                    break;
                case 'completed':
                    $statusChanged = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $statusChanged = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $statusChanged = $this->changePendingStatus($job, $data, $changedTranslator);
                    break;
                case 'withdrawafter24':
                    $statusChanged = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $statusChanged = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $statusChanged = false;
                    break;
            }

            if ($statusChanged) {
                $log_data = [
                    'old_status' => $old_status,
                    'new_status' => $data['status']
                ];
                $statusChanged = true;
                return ['statusChanged' => $statusChanged, 'log_data' => $log_data];
            }
        }
    }
    /**
     * Change the status of a job to 'timedout'.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        // Get the current status of the job
        $oldStatus = $job->status;

        // Update the job status
        $job->status = $data['status'];

        // Get user information
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Prepare data for email
        $dataEmail = [
            'user' => $user,
            'job'  => $job,
        ];

        if ($data['status'] == 'pending') {
            // Set created_at timestamp and reset email counters
            $job->created_at = now();
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;

            // Save the changes to the job
            $job->save();

            // Convert job data for email
            $jobData = $this->jobToData($job);

            // Send email to the customer about the reopened booking
            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $dataEmail);

            // Send push notification to all suitable translators
            $this->sendNotificationTranslator($job, $jobData, '*');

            return true;
        } elseif ($changedTranslator) {
            // Save the job changes
            $job->save();

            // Send confirmation email to the customer
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            return true;
        }

        return false;
    }

    /**
     * Change the status of a job to 'completed'.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeCompletedStatus(Job $job, array $data): bool
    {
        // Update the job status
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            // Check for admin comments in the case of 'timedout' status
            if ($data['admin_comments'] == '') {
                return false;
            }

            // Update admin comments
            $job->admin_comments = $data['admin_comments'];
        }

        // Save the changes to the job
        $job->save();

        return true;
    }
    /**
     * Change the status of a job to 'started'.
     *
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeStartedStatus(Job $job, array $data): bool
    {
        // Update the job status
        $job->status = $data['status'];

        // Check for admin comments
        if ($data['admin_comments'] == '') {
            return false;
        }

        // Update admin comments
        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            // Get user information
            $user = $job->user()->first();

            // Check for session time
            if ($data['sesion_time'] == '') {
                return false;
            }

            // Process session time
            $interval = $data['sesion_time'];
            $diff = explode(':', $interval);
            $job->end_at = now();
            $job->session_time = $interval;
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            // Get email and name
            $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
            $name = $user->name;

            // Prepare data for email
            $dataEmail = [
                'user'         => $user,
                'job'          => $job,
                'session_time' => $session_time,
                'for_text'     => 'faktura',
            ];

            // Send email to the customer
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $dataEmail);

            // Get the translator for the job
            $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

            // Get translator's email and name
            $translatorEmail = $translator->user->email;
            $translatorName = $translator->user->name;

            // Prepare data for email to translator
            $dataEmail['for_text'] = 'lön';

            // Send email to the translator
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            $this->mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $dataEmail);
        }

        // Save the changes to the job
        $job->save();

        return true;
    }

    /**
     * Change the status of a job to 'pending'.
     *
     * @param Job $job
     * @param array $data
     * @param bool $changedTranslator
     * @return bool
     */
    private function changePendingStatus(Job $job, array $data, bool $changedTranslator): bool
    {
        // Update the job status
        $job->status = $data['status'];

        // Check for admin comments and 'timedout' status
        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        }

        // Update admin comments
        $job->admin_comments = $data['admin_comments'];

        // Get user information
        $user = $job->user()->first();

        // Get email and name
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Prepare data for email
        $dataEmail = [
            'user' => $user,
            'job'  => $job,
        ];

        if ($data['status'] == 'assigned' && $changedTranslator) {
            // Save the job changes
            $job->save();

            // Convert job data for email
            $jobData = $this->jobToData($job);

            // Send confirmation email to the customer
            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $dataEmail);

            // Get translator details for the job
            $translator = Job::getJobsAssignedTranslatorDetail($job);

            // Send email to the new translator
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $dataEmail);

            // Get language information
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            // Send session start reminder notifications to customer and translator
            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            // Set subject for cancellation email
            $subject = 'Avbokning av bokningsnr: #' . $job->id;

            // Send cancellation email to the customer
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

            // Save the job changes
            $job->save();

            return true;
        }
    }
    
    /**
     * Send session start reminder notification.
     * TODO: Remove this method and add a service for notification.
     * This is a temporary method.
     *
     * @param mixed $user
     * @param mixed $job
     * @param mixed $language
     * @param mixed $due
     * @param mixed $duration
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->setupLogger();

        $data = [
            'notification_type' => 'session_start_remind',
        ];

        $due_explode = explode(' ', $due);
        $location = ($job->customer_physical_type == 'yes') ? 'på plats i ' . $job->town : 'telefon';

        $msg_text = [
            'en' => "Detta är en påminnelse om att du har en {$language}tolkning ({$location}) kl {$due_explode[1]} på {$due_explode[0]} som varar i {$duration} min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!",
        ];

        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers(
                $users_array,
                $job->id,
                $data,
                $msg_text,
                $this->bookingRepository->isNeedToDelayPush($user->id)
            );

            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Set up the logger.
     */
    private function setupLogger()
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }/**
    * Change job status for withdrawal after 24 hours.
    *
    * @param  \App\Job  $job
    * @param  array  $data
    * @return bool
    */
    private function changeWithdrawAfter24Status(Job $job, array $data): bool
    {
        if ($this->isValidStatus($data['status'], ['timedout'])) {
            return $this->updateJobStatus($job, $data);
        }
        return false;
    }

    /**
     * Change job status for assigned jobs.
     *
     * @param  \App\Job  $job
     * @param  array  $data
     * @return bool
     */
    private function changeAssignedStatus(Job $job, array $data): bool
    {
        $validStatuses = ['withdrawbefore24', 'withdrawafter24', 'timedout'];

        if ($this->isValidStatus($data['status'], $validStatuses)) {
            if ($this->isValidAdminComments($data['admin_comments'], $data['status'])) {
                $this->updateJobStatus($job, $data);

                if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                    $this->sendStatusChangeEmails($job);
                }

                return true;
            }
        }
        return false;
    }

    /**
     * Check if the status is valid.
     *
     * @param  string  $status
     * @param  array  $validStatuses
     * @return bool
     */
    private function isValidStatus(string $status, array $validStatuses): bool
    {
        return in_array($status, $validStatuses);
    }

    /**
     * Check if admin comments are valid based on the status.
     *
     * @param  string  $adminComments
     * @param  string  $status
     * @return bool
     */
    private function isValidAdminComments(string $adminComments, string $status): bool
    {
        return $adminComments !== '' || $status !== 'timedout';
    }

    /**
     * Update job status and admin comments.
     *
     * @param  \App\Job  $job
     * @param  array  $data
     * @return bool
     */
    private function updateJobStatus(Job $job, array $data): bool
    {
        $job->status = $data['status'];
        $job->admin_comments = $data['admin_comments'];
        $job->save();
        return true;
    }

    /**
     * Send status change emails for assigned jobs.
     *
     * @param  \App\Job  $job
     * @return void
     */
    private function sendStatusChangeEmails(Job $job): void
    {
        $user = $job->user()->first();
        $email = !empty($job->user_email) ? $job->user_email : $user->email;
        $name = $user->name;
        $dataEmail = [
            'user' => $user,
            'job'  => $job
        ];

        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $dataEmail);

        $translator = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $email = $translator->user->email;
        $name = $translator->user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $dataEmail);
    }
    /**
     * @param $current_translator
     * @param $data
     * @param $job
     * @return array
     */
    
     private function changeTranslator($currentTranslator, $data, $job)
     {
         $translatorChanged = false;
         $logData = [];
     
         if ($this->shouldChangeTranslator($currentTranslator, $data)) {
             if (!is_null($currentTranslator)) {
                 $logData = $this->changeCurrentTranslator($currentTranslator, $data);
             } elseif (isset($data['translator']) && ($data['translator'] != 0 || $data['translator_email'] != '')) {
                 $logData = $this->createNewTranslator($data, $job);
             }
     
             $translatorChanged = true;
         }
     
         return compact('translatorChanged', 'newTranslator', 'logData');
     }
     
     private function shouldChangeTranslator($currentTranslator, $data)
     {
         return !empty($currentTranslator) || (isset($data['translator']) && $data['translator'] != 0) || $data['translator_email'] != '';
     }
     
     private function changeCurrentTranslator($currentTranslator, $data)
     {
         $logData = [];
         if ((isset($data['translator']) && $currentTranslator->user_id != $data['translator']) || $data['translator_email'] != '') {
             $newTranslator = $this->createNewTranslatorInstance($currentTranslator, $data);
             $currentTranslator->cancel_at = now();
             $currentTranslator->save();
             $logData = [
                 'old_translator' => $currentTranslator->user->email,
                 'new_translator' => $newTranslator->user->email
             ];
         }
     
         return $logData;
     }
     
     private function createNewTranslatorInstance($currentTranslator, $data)
     {
         if ($data['translator_email'] != '') {
             $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
         }
     
         $newTranslator = Translator::create(array_merge($currentTranslator->toArray(), ['user_id' => $data['translator']]));
         unset($newTranslator['id']);
     
         return $newTranslator;
     }
     
     private function createNewTranslator($data, $job)
     {
         if ($data['translator_email'] != '') {
             $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
         }
     
         $newTranslator = Translator::create(['user_id' => $data['translator'], 'job_id' => $job->id]);
     
         return [
             'old_translator' => null,
             'new_translator' => $newTranslator->user->email
         ];
     }


    /**
     * @param string $oldDue
     * @param string $newDue
     * @return array
     */
    private function changeDue($oldDue, $newDue)
    {
        if ($oldDue != $newDue) {
            return [
                'dateChanged' => true,
                'logData' => [
                    'oldDue' => $oldDue,
                    'newDue' => $newDue
                ]
            ];
        }

        return ['dateChanged' => false];
    }
    /**
     * @param $job
     * @param $current_translator
     * @param $new_translator
     */
    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        // Extract user details from the job
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Prepare common data for email templates
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id;
        $data = [
            'user' => $user,
            'job'  => $job
        ];

        // Send notification to the customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        // Send notification to the old translator if exists
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        // Send notification to the new translator
        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * @param $job
     * @param $old_time
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        // Extract user details from the job
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Prepare common data for email templates
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];

        // Send notification to the customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        // Send notification to the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * @param $job
     * @param $old_lang
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        // Extract user details from the job
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Prepare common data for email templates
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id;
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];

        // Send notification to the customer
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        // Send notification to the assigned translator
        $translator = Job::getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';

        // Fetch language from the job and construct message text
        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
        $msg_text = [
            'en' => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        // Check if push notification needs to be sent and then send it
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }
    /**
     * Function to send a notification when the admin cancels a job
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        // Retrieve the job using the provided ID
        $job = Job::findOrFail($job_id);

        // Retrieve user meta information associated with the job's user
        $user_meta = $job->user->userMeta()->first();

        // Initialize an array to store job information for sending Push notification
        $data = [];

        // Populate the data array with relevant job information
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        // Extract due date and time from the job and add to the data array
        $due_Date = explode(" ", $job->due);
        $data['due_date'] = $due_Date[0];
        $data['due_time'] = $due_Date[1];

        // Initialize an array to store job_for information
        $data['job_for'] = [];

        // Add gender information to the job_for array if present in the job
        if ($job->gender != null) {
            $data['job_for'][] = ($job->gender == 'male') ? 'Man' : 'Kvinna';
        }

        // Add certified information to the job_for array if present in the job
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } elseif ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        // Send Push notification to all suitable translators
        $this->sendNotificationTranslator($job, $data, '*');
    }
    /**
     * Send session start reminder notification
     * @param $user
     * @param $job
     * @param $language
     * @param $due
     * @param $duration
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        // Initialize an array to store notification data
        $data = [];

        // Set the notification type
        $data['notification_type'] = 'session_start_remind';

        // Determine the message text based on the customer's physical type
        $msg_text = [
            'en' => ($job->customer_physical_type == 'yes')
                ? 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
                : 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'
        ];

        // Check if push notification needs to be sent and then send it
        if ($this->bookingRepository->isNeedToSendPush($user->id)) {
            $users_array = [$user];
            $this->bookingRepository->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->bookingRepository->isNeedToDelayPush($user->id));
        }
    }

    /**
     * making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;
        foreach ($users as $oneUser) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }
            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($oneUser->email) . '"}';
        }
        $user_tags .= ']';
        return $user_tags;
    }

    /**
     * Accept a job based on provided data and user
     *
     * @param array $data
     * @param User $user
     * @return array
     */
    public function acceptJob(array $data, User $user): array
    {
        // Retrieve admin email and sender email from configuration
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');

        // Alias the user parameter for clarity
        $cuser = $user;
        
        // Extract job ID from data
        $job_id = $data['job_id'];
        
        // Find the job with the given ID
        $job = Job::findOrFail($job_id);

        // Check if the translator is not already booked for the job
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            // Check if the job status is pending and insert the translator-job relationship
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                // Update the job status to 'assigned'
                $job->status = 'assigned';
                $job->save();
                
                // Retrieve user information associated with the job
                $user = $job->user()->first();
                $mailer = new AppMailer();

                // Determine email, name, and subject based on user_email presence
                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                } else {
                    $email = $user->email;
                    $name = $user->name;
                    $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                }

                // Prepare data for email notification
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];

                // Send email notification to the user
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                // @todo Add flash message here.

                // Retrieve potential jobs for the current user
                $jobs = $this->getPotentialJobs($cuser);
                $response = [
                    'list'   => json_encode(['jobs' => $jobs, 'job' => $job], true),
                    'status' => 'success',
                ];
            } else {
                // Response for failure to insert translator-job relationship
                $response['status'] = 'fail';
                $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
            }
        } else {
            // Response for already booked or pending status failure
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /**
     * Accept a job based on the provided job ID and user
     *
     * @param int $job_id
     * @param User $cuser
     * @return array
     */
    public function acceptJobWithId(int $job_id, User $cuser): array
    {
        // Retrieve admin email and sender email from configuration
        $adminemail = config('app.admin_email');
        $adminSenderEmail = config('app.admin_sender_email');
        
        // Find the job with the given ID
        $job = Job::findOrFail($job_id);
        
        // Initialize the response array
        $response = [];

        // Check if the translator is not already booked for the job
        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            // Check if the job status is pending and insert the translator-job relationship
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                // Update the job status to 'assigned'
                $job->status = 'assigned';
                $job->save();
                
                // Retrieve user information associated with the job
                $user = $job->user()->first();
                $mailer = new AppMailer();

                // Determine email, name, and subject based on user_email presence
                if (!empty($job->user_email)) {
                    $email = $job->user_email;
                    $name = $user->name;
                } else {
                    $email = $user->email;
                    $name = $user->name;
                }

                // Prepare data for email notification
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $data = [
                    'user' => $user,
                    'job'  => $job
                ];

                // Send email notification to the user
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

                // Prepare data for push notification
                $pushData = [
                    'notification_type' => 'job_accepted',
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    'en' => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.',
                ];

                // Check if push notification is needed and send it to the specific user
                if ($this->isNeedToSendPush($user->id)) {
                    $users_array = [$user];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $pushData, $msg_text, $this->isNeedToDelayPush($user->id));
                }

                // Your Booking is accepted successfully
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } else {
                // Response for failure to insert translator-job relationship
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } else {
            // Response for already booked or pending status failure
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }

        return $response;
    }
    
    /**
    * Cancel a job via AJAX based on the provided data and user
    *
    * @param array $data
    * @param User $user
    * @return array
    */
    public function cancelJobAjax(array $data, User $user): array
    {
        $response = [];

        /*
        * @todo
        * Add 24hrs logging here.
        * If the cancellation is before 24 hours before the booking time:
        * - Supplier will be informed. Flow ends.
        * If the cancellation is within 24 hours:
        * - Translator will be informed.
        * - Customer will get an addition to his number of bookings, and we will charge for it.
        * So, we must treat it as if it was an executed session.
        */

        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);
        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            
            // Check if the cancellation is before or after 24 hours
            if ($job->withdraw_at->diffInHours($job->due) >= 24) {
                $job->status = 'withdrawbefore24';
            } else {
                $job->status = 'withdrawafter24';
            }

            $job->save();
            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            $response['jobstatus'] = 'success';

            if ($translator) {
                $data = [
                    'notification_type' => 'job_cancelled',
                ];
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    'en' => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.',
                ];

                // Send Session Cancel Push to Translator
                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = [$translator];
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id));
                }
            }
        } else {
            // Check if the job due time is more than 24 hours from now
            if ($job->due->diffInHours(Carbon::now()) > 24) {
                $customer = $job->user()->first();

                if ($customer) {
                    $data = [
                        'notification_type' => 'job_cancelled',
                    ];
                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        'en' => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.',
                    ];

                    // Send Session Cancel Push to Customer
                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = [$customer];
                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));
                    }
                }

                $job->status = 'pending';
                $job->created_at = now();
                $job->will_expire_at = TeHelper::willExpireAt($job->due, now());
                $job->save();

                // Event::fire(new JobWasCanceled($job));
                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                // Send Push to all suitable translators
                $this->sendNotificationTranslator($job, $data, $translator->id);

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning över telefon. Tack!';
            }
        }

        return $response;
    }
    
    /**
     * Get potential jobs based on the user type (paid, rws, unpaid) and other criteria
     *
     * @param User $cuser
     * @return \Illuminate\Database\Eloquent\Collection|Job[]
     */
    public function getPotentialJobs(User $cuser)
    {
        $cuserMeta = $cuser->userMeta;
        $jobType = 'unpaid';
        $translatorType = $cuserMeta->translator_type;

        // Determine the job type based on the translator type
        if ($translatorType == 'professional') {
            $jobType = 'paid';   // Show all jobs for professionals
        } elseif ($translatorType == 'rwstranslator') {
            $jobType = 'rws';  // For rwstranslators, only show rws jobs
        } elseif ($translatorType == 'volunteer') {
            $jobType = 'unpaid';  // For volunteers, only show unpaid jobs
        }

        // Get the languages of the user
        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $cuserMeta->gender;
        $translatorLevel = $cuserMeta->translator_level;

        // Get the potential job IDs based on user and job criteria
        $jobIds = Job::getJobs($cuser->id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);

        // Iterate through jobs and filter based on additional criteria
        foreach ($jobIds as $key => $job) {
            $jobUserId = $job->user_id;

            // Check if the job is assigned to a particular translator
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);

            // Check if the user can accept the particular job
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);

            // Check if the job's physical location allows for user acceptance
            $checkTown = Job::checkTowns($jobUserId, $cuser->id);

            // Filter jobs based on specific and particular job criteria
            if ($job->specific_job == 'SpecificJob' && $job->check_particular_job == 'userCanNotAcceptJob') {
                unset($jobIds[$key]);
            }

            // Filter jobs based on physical location criteria
            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false) {
                unset($jobIds[$key]);
            }
        }

        // Return the filtered job IDs
        return $jobIds;
    }
    /**
     * End a job, update status to 'completed', and send notification emails
     *
     * @param array $postData
     * @return array
     */
    public function endJob(array $postData)
    {
        $completedDate = date('Y-m-d H:i:s');
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);

        // Check if the job status is not 'started', return success if true
        if ($jobDetail->status != 'started') {
            return ['status' => 'success'];
        }

        // Calculate the session time duration
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;

        // Update job details and status to 'completed'
        $job = $jobDetail;
        $job->end_at = date('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        // Get user details for sending notification emails
        $user = $job->user()->first();
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;

        // Send notification email to the user for invoice
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $sessionExplode = explode(':', $job->session_time);
        $sessionTime = $sessionExplode[0] . ' tim ' . $sessionExplode[1] . ' min';
        $data = [
            'user'         => $user,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'faktura'
        ];
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        // Save the updated job details
        $job->save();

        // Get the translator details
        $translator = $job->translatorJobRel()->where('completed_at', null)->where('cancel_at', null)->first();

        // Fire an event for session end
        Event::fire(new SessionEnded($job, ($postData['user_id'] == $job->user_id) ? $translator->user_id : $job->user_id));

        // Get user details for sending notification email to the translator for payment
        $translatorUser = $translator->user()->first();
        $translatorEmail = $translatorUser->email;
        $translatorName = $translatorUser->name;

        // Send notification email to the translator for payment
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $data = [
            'user'         => $translatorUser,
            'job'          => $job,
            'session_time' => $sessionTime,
            'for_text'     => 'lön'
        ];
        $mailer->send($translatorEmail, $translatorName, $subject, 'emails.session-ended', $data);

        // Update translator details with completed date and completed by user ID
        $translator->completed_at = $completedDate;
        $translator->completed_by = $postData['user_id'];
        $translator->save();

        // Return success status
        return ['status' => 'success'];
    }

    public function customerNotCall($postData)
    {
        // Get the current date and time
        $completedDate = now();
    
        // Retrieve job details by ID with translatorJobRel relationship
        $jobId = $postData["job_id"];
        $jobDetail = Job::with('translatorJobRel')->find($jobId);
    
        // Calculate the interval between due date and completion date
        $dueDate = $jobDetail->due;
        $start = date_create($dueDate);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->format('%h:%i:%s');
    
        // Update job details
        $jobDetail->end_at = $completedDate;
        $jobDetail->status = 'not_carried_out_customer';
    
        // Retrieve translator associated with the job
        $translator = $jobDetail->translatorJobRel()
            ->whereNull('completed_at')
            ->whereNull('cancel_at')
            ->first();
    
        // Update translator details
        $translator->completed_at = $completedDate;
        $translator->completed_by = $translator->user_id;
    
        // Save changes to the database
        $jobDetail->save();
        $translator->save();
    
        // Prepare and return the response
        $response['status'] = 'success';
        return $response;
    }
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->__authenticatedUser;
        $consumer_type = optional($cuser)->consumer_type;
    
        $allJobs = Job::query();
    
        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $this->applySuperadminFilters($allJobs, $requestdata);
    
        } else {
            $this->applyRegularUserFilters($allJobs, $requestdata, $consumer_type);
        }
    
        $allJobs->orderBy('created_at', 'desc')
            ->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');
    
        if ($limit == 'all') {
            $allJobs = $allJobs->get();
        } else {
            $allJobs = $allJobs->paginate(15);
        }
    
        return $allJobs;
    }
    
    private function applySuperadminFilters($allJobs, $requestdata)
    {
        $this->applyFeedbackFilters($allJobs, $requestdata);
    
        // Other filters for superadmin
    
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $this->applyIdFilter($allJobs, $requestdata['id']);
        }
    
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
    
        // Add other superadmin-specific filters here...
    
        $this->applyCommonFilters($allJobs, $requestdata);
    }
    
    private function applyIdFilter($allJobs, $id)
    {
        if (is_array($id)) {
            $allJobs->whereIn('id', $id);
        } else {
            $allJobs->where('id', $id);
        }
    }
    
    // Add other superadmin-specific filter methods here...
    
    private function applyCommonFilters($allJobs, $requestdata)
    {
        // Common filters for both superadmin and regular user
    
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('status', $requestdata['status']);
        }
    
        if (isset($requestdata['expired_at']) && $requestdata['expired_at'] != '') {
            $allJobs->where('expired_at', '>=', $requestdata['expired_at']);
        }
    
    }
    
    private function applyFeedbackFilters($allJobs, $requestdata)
    {
        if (isset($requestdata['feedback']) && $requestdata['feedback'] != 'false') {
            $allJobs->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($q) {
                    $q->where('rating', '<=', '3');
                });
            if (isset($requestdata['count']) && $requestdata['count'] != 'false') {
                return ['count' => $allJobs->count()];
            }
        }
    }
    
    private function applyRegularUserFilters($allJobs, $requestdata, $consumer_type)
    {
        $allJobs->where('job_type', '=', ($consumer_type == 'RWS') ? 'rws' : 'unpaid');
        $this->applyFeedbackFilters($allJobs, $requestdata);
    
        // Other filters for regular users
    
        if (isset($requestdata['id']) && $requestdata['id'] != '') {
            $this->applyIdFilter($allJobs, $requestdata['id']);
        }
    
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('from_language_id', $requestdata['lang']);
        }
    
        // Add other regular user-specific filters here...
    
        $this->applyCommonFilters($allJobs, $requestdata);
    }
    
    
    private function applyCommonFilters($allJobs, $requestdata)
    {
        // Common filters for both superadmin and regular user
    
        if (isset($requestdata['customer_email']) && count($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $this->applyCustomerEmailFilter($allJobs, $requestdata['customer_email']);
        }
    
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            $this->applyCreatedAtFilter($allJobs, $requestdata);
        }
    
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "due") {
            $this->applyDueFilter($allJobs, $requestdata);
        }
    
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn('job_type', $requestdata['job_type']);
        }
    
        if (isset($requestdata['physical'])) {
            $this->applyPhysicalFilter($allJobs, $requestdata['physical']);
        }
    
        if (isset($requestdata['phone'])) {
            $this->applyPhoneFilter($allJobs, $requestdata['phone']);
        }
    
        if (isset($requestdata['flagged'])) {
            $this->applyFlaggedFilter($allJobs, $requestdata['flagged']);
        }
    
        if (isset($requestdata['distance']) && $requestdata['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }
    
        if (isset($requestdata['salary']) &&  $requestdata['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }
    
        if (isset($requestdata['count']) && $requestdata['count'] == 'true') {
            return ['count' => $allJobs->count()];
        }
    
        if (isset($requestdata['consumer_type']) && $requestdata['consumer_type'] != '') {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestdata) {
                $q->where('consumer_type', $requestdata['consumer_type']);
            });
        }
    
        if (isset($requestdata['booking_type'])) {
            $this->applyBookingTypeFilter($allJobs, $requestdata['booking_type']);
        }
    }
    public function alerts()
    {
        $jobs = Job::all();
        $sesJobs = [];
        $jobId = [];
        $diff = [];
        $i = 0;
    
        foreach ($jobs as $job) {
            $sessionTime = explode(':', $job->session_time);
            if (count($sessionTime) >= 3) {
                $diff[$i] = ($sessionTime[0] * 60) + $sessionTime[1] + ($sessionTime[2] / 60);
    
                if ($diff[$i] >= $job->duration && $diff[$i] >= $job->duration * 2) {
                    $sesJobs [$i] = $job;
                }
    
                $i++;
            }
        }
    
        $jobId = array_column($sesJobs, 'id');
    
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');
    
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
    
        if ($cuser && $cuser->is('superadmin')) {
            $allJobs = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $jobId)
                ->where('jobs.ignore', 0);
    
            $this->applyFilters($allJobs, $requestdata);
    
            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
    
            return [
                'allJobs' => $allJobs,
                'languages' => $languages,
                'all_customers' => $all_customers,
                'all_translators' => $all_translators,
                'requestdata' => $requestdata
            ];
        }
    
        return [];
    }
    
    private function applyFilters($allJobs, $requestdata)
    {
        $this->applyLanguageFilter($allJobs, $requestdata);
        $this->applyStatusFilter($allJobs, $requestdata);
        $this->applyUserEmailFilter($allJobs, 'customer_email', 'jobs.user_id');
        $this->applyUserEmailFilter($allJobs, 'translator_email', 'translator_job_rel.user_id');
        $this->applyTimeTypeFilter($allJobs, $requestdata, 'created_at', 'jobs.ignore');
        $this->applyTimeTypeFilter($allJobs, $requestdata, 'due', 'jobs.ignore');
        $this->applyJobTypeFilter($allJobs, $requestdata, 'jobs.job_type');
    }
    
    private function applyLanguageFilter($allJobs, $requestdata)
    {
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
        }
    }
    
    private function applyStatusFilter($allJobs, $requestdata)
    {
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('jobs.status', $requestdata['status']);
        }
    }
    
    private function applyUserEmailFilter($allJobs, $key, $column)
    {
        if (isset($requestdata[$key]) && $requestdata[$key] != '') {
            $user = DB::table('users')->where('email', $requestdata[$key])->first();
            if ($user) {
                $allJobs->where($column, '=', $user->id);
            }
        }
    }
    
    private function applyTimeTypeFilter($allJobs, $requestdata, $column, $ignoreColumn)
    {
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where("jobs.$column", '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where("jobs.$column", '<=', $to);
            }
            $allJobs->orderBy("jobs.$column", 'desc');
        }
    }
    
    private function applyJobTypeFilter($allJobs, $requestdata, $column)
    {
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn($column, $requestdata['job_type']);
        }
    }
    
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();
        $all_customers = DB::table('users')->where('user_type', '1')->pluck('email');
        $all_translators = DB::table('users')->where('user_type', '2')->pluck('email');
    
        $cuser = Auth::user();
        $consumer_type = TeHelper::getUsermeta($cuser->id, 'consumer_type');
    
        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $allJobs = Job::join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0)
                ->where('jobs.status', 'pending')
                ->where('jobs.due', '>=', Carbon::now());
    
            $this->applyFilters($allJobs, $requestdata);
    
            $allJobs->select('jobs.*', 'languages.language')
                ->orderBy('jobs.created_at', 'desc')
                ->paginate(15);
    
            return [
                'allJobs' => $allJobs,
                'languages' => $languages,
                'all_customers' => $all_customers,
                'all_translators' => $all_translators,
                'requestdata' => $requestdata
            ];
        }
    
        return [];
    }
    
    private function applyFilters($allJobs, $requestdata)
    {
        $this->applyLanguageFilter($allJobs, $requestdata);
        $this->applyStatusFilter($allJobs, $requestdata);
        $this->applyUserEmailFilter($allJobs, 'customer_email', 'jobs.user_id');
        $this->applyUserEmailFilter($allJobs, 'translator_email', 'translator_job_rel.user_id');
        $this->applyTimeTypeFilter($allJobs, $requestdata, 'created_at');
        $this->applyTimeTypeFilter($allJobs, $requestdata, 'due');
        $this->applyJobTypeFilter($allJobs, $requestdata, 'jobs.job_type');
    }
    
    private function applyLanguageFilter($allJobs, $requestdata)
    {
        if (isset($requestdata['lang']) && $requestdata['lang'] != '') {
            $allJobs->whereIn('jobs.from_language_id', $requestdata['lang']);
        }
    }
    
    private function applyStatusFilter($allJobs, $requestdata)
    {
        if (isset($requestdata['status']) && $requestdata['status'] != '') {
            $allJobs->whereIn('jobs.status', $requestdata['status']);
        }
    }
    
    private function applyUserEmailFilter($allJobs, $key, $column)
    {
        if (isset($requestdata[$key]) && $requestdata[$key] != '') {
            $user = DB::table('users')->where('email', $requestdata[$key])->first();
            if ($user) {
                $allJobs->where($column, '=', $user->id);
            }
        }
    }
    
    private function applyTimeTypeFilter($allJobs, $requestdata, $column)
    {
        if (isset($requestdata['filter_timetype']) && $requestdata['filter_timetype'] == "created") {
            if (isset($requestdata['from']) && $requestdata['from'] != "") {
                $allJobs->where("jobs.$column", '>=', $requestdata["from"]);
            }
            if (isset($requestdata['to']) && $requestdata['to'] != "") {
                $to = $requestdata["to"] . " 23:59:00";
                $allJobs->where("jobs.$column", '<=', $to);
            }
            $allJobs->orderBy("jobs.$column", 'desc');
        }
    }
    
    private function applyJobTypeFilter($allJobs, $requestdata, $column)
    {
        if (isset($requestdata['job_type']) && $requestdata['job_type'] != '') {
            $allJobs->whereIn($column, $requestdata['job_type']);
        }
    }

    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();
        return ['success', 'Changes saved'];
    }

    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();
        return ['success', 'Changes saved'];
    }
    
    public function reopen($request)
    {
        $jobid = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($jobid)->toArray();
        
        $data = $this->prepareJobData($job, $userid);
        $datareopen = $this->prepareReopenData($job);

        if ($job['status'] != 'timedout') {
            $affectedRows = $this->updateJob($jobid, $datareopen);
            $new_jobid = $jobid;
        } else {
            $affectedRows = $this->createNewJob($job);
            $new_jobid = $affectedRows['id'];
        }

        $this->updateTranslatorData($jobid, $data['cancel_at']);
        $Translator = $this->createTranslator($data);

        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            return ["Booking cancelled successfully!"];
        } else {
            return ["Please try again!"];
        }
    }

    private function prepareJobData($job, $userid)
    {
        return [
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
            'updated_at' => now(),
            'user_id' => $userid,
            'job_id' => $job['id'],
            'cancel_at' => now(),
        ];
    }

    private function prepareReopenData($job)
    {
        return [
            'status' => 'pending',
            'created_at' => now(),
            'will_expire_at' => TeHelper::willExpireAt($job['due'], now()),
        ];
    }

    private function updateJob($jobid, $datareopen)
    {
        return Job::where('id', $jobid)->update($datareopen);
    }

    private function createNewJob($job)
    {
        $job['status'] = 'pending';
        $job['created_at'] = now();
        $job['updated_at'] = now();
        $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], now());
        $job['cust_16_hour_email'] = 0;
        $job['cust_48_hour_email'] = 0;
        $job['admin_comments'] = 'This booking is a reopening of booking #' . $job['id'];

        return Job::create($job);
    }

    private function updateTranslatorData($jobid, $cancel_at)
    {
        Translator::where('job_id', $jobid)->where('cancel_at', NULL)->update(['cancel_at' => $cancel_at]);
    }

    private function createTranslator($data)
    {
        return Translator::create($data);
    }

}