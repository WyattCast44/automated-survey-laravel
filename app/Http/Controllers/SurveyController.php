<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Cookie;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use App\Survey;

use Services_Twilio_Twiml;

class SurveyController extends Controller
{
    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function showVoice($id)
    {
        $surveyToTake = Survey::find($id);
        $voiceResponse = new Services_Twilio_Twiml();

        if (is_null($surveyToTake)) {
            return $this->_noSuchVoiceSurvey($voiceResponse);
        }
        $surveyTitle = $surveyToTake->title;
        $voiceResponse->say("Hello and thank you for taking the $surveyTitle survey!");
        $voiceResponse->redirect($this->_urlForFirstQuestion($surveyToTake, 'voice'), ['method' => 'GET']);

        return response($voiceResponse)->header('Content-Type', 'application/xml');
    }

    public function showSms($id)
    {
        return 'TwiML';
    }

    public function showResults($surveyId)
    {
        $survey = \App\Survey::find($surveyId);
        $responsesByCall = \App\QuestionResponse::responsesForSurveyByCall($surveyId)
                         ->get()
                         ->groupBy('session_sid')
                         ->values();

        return response()->view(
            'surveys.results',
            ['survey' => $survey, 'responses' => $responsesByCall]
        );
    }

    public function showFirstSurveyResults()
    {
        $firstSurvey = Survey::first();
        return redirect(route('survey.results', ['survey' => $firstSurvey->id]))
                ->setStatusCode(303);
    }

    public function connectVoice()
    {
        $response = new Services_Twilio_Twiml();
        $redirectResponse = $this->_redirectWithFirstSurvey('survey.show.voice', $response);
        return response($redirectResponse)->header('Content-Type', 'application/xml');
    }

    public function connectSms(Request $request)
    {
        $response = $this->_getNextSmsStepFromCookies($request);
        return $response->header('Content-Type', 'application/xml');
    }

    private function _getNextSmsStepFromCookies($request) {
        $response = new Services_Twilio_Twiml();
        if (strtolower($request->input('Body')) === 'start') {
            $messageSid = $request->input('MessageSid');

            return response($this->_redirectWithFirstSurvey('survey.show.sms', $response))
                        ->withCookie('survey_session', $messageSid);
        }

        $currentQuestion = $request->cookie('current_question');
        $surveySession = $request->cookie('survey_session');

        if (!$currentQuestion || !$surveySession) {
            return response($this->_smsSuggestCommand($response));
        }

        return response($this->_redirectToSmsQuestion($response, $currentQuestion));
    }

    private function _redirectToSmsQuestion($response, $currentQuestion) {
        $firstSurvey = Survey::first();
        $storeRoute = route('response.store.sms', ['survey' => $firstSurvey->id, 'question' => $currentQuestion]);
        $response->redirect($storeRoute, ['method' => 'POST']);

        return $response;
    }

    private function _smsSuggestCommand($response) {
        $response->message('You have no active surveys. Reply with "Start" to begin.');
        return $response;
    }

    private function _urlForFirstQuestion($survey, $routeType)
    {
        return route(
            'question.show.' . $routeType,
            ['survey' => $survey->id,
             'question' => $survey->questions()->first()]
        );
    }

    private function _noSuchVoiceSurvey($voiceResponse)
    {
        $voiceResponse->say('Sorry, we could not find the survey to take');
        $voiceResponse->say('Good-bye');
        $voiceResponse->hangup();

        return $voiceResponse;
    }

    private function _noSuchSmsSurvey($messageResponse)
    {
        return $messageResponse->message('Sorry, we could not find the survey to take. Good-bye');
    }

    private function _redirectWithFirstSurvey($routeName, $response)
    {
        $firstSurvey = Survey::first();

        if (is_null($firstSurvey)) {
            if ($routeName === 'survey.show.voice') {
                return $this->_noSuchVoiceSurvey($response);
            }
            return $this->_noSuchSmsSurvey($response);
        }

        $response->redirect(
            route($routeName, ['id' => $firstSurvey->id]),
            ['method' => 'GET']
        );
        return $response;
    }
}
