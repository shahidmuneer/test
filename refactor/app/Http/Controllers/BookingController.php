<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Http\Requests;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
//Added Arr support for update method
use Illuminate\Support\Arr;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }
    /**
     * Get user's jobs or all jobs based on the user's role.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
       // It's a good practice to use meaningful variable names.
        // Instead of $user_id, consider using $requestedUserId.
        $requestedUserId = $request->get('user_id');

        // Avoid using '==' for comparison. Use '===' for strict type-checking.
        // If $requestedUserId is set and not empty, proceed with fetching user's jobs.
        if ($requestedUserId !== null && $requestedUserId !== '') {
            $status=200;
            $response = $this->repository->getUsersJobs($requestedUserId);
        } 
        // Use '===' for strict type-checking and '===' is safer for comparing with constants.
        // Also, it's better to explicitly check for user_type existence before accessing it.
        // Consider using more meaningful constant names instead of 'ADMIN_ROLE_ID' and 'SUPERADMIN_ROLE_ID'.
        elseif (
            isset($request->__authenticatedUser->user_type) &&
            ($request->__authenticatedUser->user_type === env('ADMIN_ROLE_ID') ||
            $request->__authenticatedUser->user_type === env('SUPERADMIN_ROLE_ID'))
        ) {
            // If the user is an admin or superadmin, fetch all jobs.
            // Pass $request as an argument if needed, but it's better to avoid relying on the whole $request object.
            $response = $this->repository->getAll($request);
        } 
        // It's always good to handle unexpected cases explicitly, for example, by setting $response to an error.
        else {
            // Handle the case where neither $requestedUserId is provided nor the user is an admin/superadmin.
            $response = ['error' => 'Invalid request'];
            $status=405;
        }
        //Correct the response($response) function to correct response method and convert to json
        return response()->json($data, $status);
    }

    /**
     * Get job details by ID.
     *
     * @param $id The job ID.
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
         // Use the findWith method to eager load relationships
        $job = $this->repository->findWith($id, ['translatorJobRel.user']);

        // Use the json method for a JSON response
        return response()->json($job);
    }

    /**
     * Store a new job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $data = $request->all();

        // Use the create method on the repository to handle the store logic
        $response = $this->repository->create($request->__authenticatedUser, $data);

        // Use the json method JSON response
        return response()->json($response);
    }

      /**
     * Update a job by ID.
     *
     * @param $id The job ID.
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function update($id, Request $request)
    {
        // Used except method to remove unnecessary elements from the data array.
        $data = $request->except('_token', 'submit');
        // Used camelCase for the $authenticatedUser variable to follow Laravel conventions.
        $authenticatedUser = $request->__authenticatedUser;

        // Assuming updateJob is a custom method in repository model
        $response = $this->repository->updateJob($id, $data, $authenticatedUser);

        // Use the json method for a cleaner and more explicit JSON response
        return response()->json($response);
    }

    /**
     * Send email for immediate job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function immediateJobEmail(Request $request)
    {
        $adminSenderEmail = config('app.adminemail');
        $data = $request->all();

        // Calling StoreJob Email to send job email
        $response = $this->repository->storeJobEmail($data);

        // Use the json method for JSON response
        return response()->json($response);
    }

    
    /**
     * Get user's job history.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function getHistory(Request $request)
    {
        //Defining User id seperately with request 
        $user_id = $request->get('user_id');
        $status=200;

        if ($user_id) {
            // Assuming getUsersJobsHistory is a custom method in your repository
            $response = $this->repository->getUsersJobsHistory($user_id, $request);
            // Use the json method for JSON response
            return response()->json($response);
        }

        // giving user message to makesure that they give userid in the param
        $response=["message"=>"User id cannot be empty"];
        $status=404;
        // Use the json method for JSON response and return  response in case of success
        return response()->json($response,404);
    }

   
    /**
     * Accept a job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // Accepting user job as an admin 
        $response = $this->repository->acceptJob($data, $user);

        // Use the json method for JSON response
        return response()->json($response);
    }

    /**
     * Accept a job with specified ID.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function acceptJobWithId(Request $request)
    {
        $jobId = $request->input('job_id');
        $user = $request->__authenticatedUser;

        // This funciton will accept job with respect to user id
        $response = $this->repository->acceptJobWithId($jobId, $user);

        // Use the json method for JSON response and return 200 response in case of success
        return response()->json($response,200);
    }

   /**
     * Cancel a job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function cancelJob(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        // This function will cancel job using ajax method
        $response = $this->repository->cancelJobAjax($data, $user);

        // Use the json method for JSON response and return 200 response in case of success
        return response()->json($response,200);
    }

    /**
     * End a job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function endJob(Request $request)
    {
        $data = $request->all();

        //Handles the process of ending a job, updating relevant details, and sending notification emails.
        $response = $this->repository->endJob($data);


        // Use the json method for JSON response and return 200 response in case of success
        return response()->json($response,200);


    }

    
    /**
     * Mark a job as "not carried out by the customer."
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function customerNotCall(Request $request)
    {
        $data = $request->all();

        //Marks a job as "not carried out by the customer" and updates relevant timestamps.
        $response = $this->repository->customerNotCall($data);

        // Use the json method for JSON response and return 200 response in case of success
        return response()->json($response,200);
    }

    
    /**
     * Get potential jobs for the authenticated user.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function getPotentialJobs(Request $request)
    {
        $data = $request->all();
        $user = $request->__authenticatedUser;

        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    /**
     * Update distance and other details for a job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function distanceFeed(Request $request)
    {
        $data = $request->all();
        $message="";
        $status="";

        // Extract values from the request data, with default values set to empty strings
        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';
        $jobid = $data['jobid'] ?? '';
        $session = $data['session_time'] ?? '';

        // Set flagged, manually_handled, by_admin, and admincomment based on the request data
        $flagged = ($data['flagged'] == 'true') ? 'yes' : 'no';
        $manually_handled = ($data['manually_handled'] == 'true') ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] == 'true') ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? '';

        // Update the Distance model if time or distance is provided
        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        // Update the Job model with additional details
        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            $status=Job::where('id', '=', $jobid)->update([
                'admin_comments' => $admincomment,
                'flagged' => $flagged,
                'session_time' => $session,
                'manually_handled' => $manually_handled,
                'by_admin' => $by_admin
            ]);
            
            // Check if the update was successful
            if ($status){$message="Record Updated"; $status=200;}
            else{$message="Error Updating Record"; $status=500;}// 500 indicates Internal Server Error
            // Use the json method for JSON response and return 200 response in case of success
            return response()->json($message,$status);
        }else {
            $message="No Update Provided";
            $status=402;
            // Use the json method for JSON response and return 200 response in case of success
            return response()->json($message,$status);
        }
    }

    
    /**
     * Reopen a job.
     *
     * @param Request $request The HTTP request.
     * @return \Illuminate\Http\JsonResponse
     */
    public function reopen(Request $request)
    {
        $data = $request->all();
        // Reopen a job, creating a new instance with updated status and timestamps.
        $response = $this->repository->reopen($data);
        // Use the json method for JSON response and return 200 response in case of success    
        return response()->json($response,200);
    }
    /**
     * Resend push notifications to the translator for the specified job.
     *
     * @param Request $request The HTTP request containing 'jobid'.
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        //check if jobid is not in the request object before finding the booking
        if (!isset($data['jobid'])) {
            return response()->json(['error' => 'Job ID is missing in the request.'], 400);
        }
        //explicitly defining the job id in a variable
        $jobId=$data['jobid'];
        //Fetch responsitory from database based upno jobId
        $job = $this->repository->find($jobId);
        // Check if the job was not found
        if (!$job) {
            // Return an error response if the job is not found
            return response()->json(['error' => 'Job not found.'], 404);
        }
        // Convert job information to an array for sending push notifications.
        $job_data = $this->repository->jobToData($job);
        // Send push notification to the translator
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response()->json(['success' => 'Push sent'],200);
    }

    /**
     * Resend SMS notifications to the translator for the specified job.
     *
     * @param Request $request The HTTP request containing 'jobid'.
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendSMSNotifications(Request $request)
    {
        // Extract data from the request
        $data = $request->all();

        // Check if 'jobid' is present in the request data
        if (!isset($data['jobid'])) {
            // Return an error response if 'jobid' is missing
            return response()->json(['error' => 'Job ID is missing in the request.'], 400);
        }

        // Assign the job ID to a variable for clarity
        $jobId = $data['jobid'];

        // Find the job in the repository based on the job ID
        $job = $this->repository->find($jobId);

        // Check if the job was not found
        if (!$job) {
            // Return an error response if the job is not found
            return response()->json(['error' => 'Job not found.'], 404);
        }

        try {
            // Send SMS notification to the translator
            $this->repository->sendSMSNotificationToTranslator($job);
            return response()->json(['success' => 'SMS sent'], 200);
        } catch (\Exception $e) {
            // Return an error response if SMS sending fails
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

}