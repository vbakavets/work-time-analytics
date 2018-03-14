<?php

function GetTogglCurrentTimeMs($user)
{
    $url = 'https://toggl.com/api/v8/time_entries/current';

    $opts = array(
        'http'=>array(
        'method'=>"GET",
        'header'=>"Authorization: Basic ".base64_encode($user['apiToken'].":api_token")."\r\n"
        )
    );
    $context = stream_context_create($opts);

    $currentTimeEntry = json_decode(file_get_contents($url, false, $context));

    if (!isset($currentTimeEntry) || !isset($currentTimeEntry->data) || !isset($currentTimeEntry->data->duration)){
        return null;
    }

    $currentUnixTimeSec = round(microtime(true));

    // From Toggl API docs:
    // duration: time entry duration in seconds. If the time entry is currently running, 
    // the duration attribute contains a negative value, denoting the start of the time 
    // entry in seconds since epoch (Jan 1 1970). The correct duration can be calculated 
    // as current_time + duration, where current_time is the current time in seconds since 
    // epoch. (integer, required)
    // For more info go to https://github.com/toggl/toggl_api_docs/blob/master/chapters/time_entries.md#get-running-time-entry
    
    return ($currentUnixTimeSec + $currentTimeEntry->data->duration) * 1000; // Convert to miliseconds
}

function GetTogglWeeklyReport($user, $startDate)
{
    $url = 'https://toggl.com/reports/api/v2/weekly?';

    $queryParameters[] = 'user_agent='.$user['userAgent'];
    $queryParameters[] = 'workspace_id='.$user['workspaceId'];
    $queryParameters[] = 'since='.$startDate;

    $url = $url.implode('&',$queryParameters);

    $opts = array(
        'http'=>array(
        'method'=>"GET",
        'header'=>"Authorization: Basic ".base64_encode($user['apiToken'].":api_token")."\r\n"
        )
    );
    $context = stream_context_create($opts);

    return json_decode(file_get_contents($url, false, $context));
}

function ConvertMilisecondsToHours(int $timeInMs = null)
{
    return $timeInMs/1000/60/60;
}

use Symfony\Component\Yaml\Yaml;

date_default_timezone_set('UTC');

require __DIR__ . '/../vendor/autoload.php';
define('DEBUG_MODE', false);


$config = Yaml::parseFile(__DIR__.'/../config/config.yml');
$users = $config['users'];

$previousWeekData = [];
$thisWeekData = [];
$thisWeeDailyData = [];

foreach ($users as $username => $user) {
    $color = isset($user['color']) ? $user['color'] : '';

    $previousWeekTime = $thisWeekTime = 0;
    $thisWeekTimePerDay = array_fill(0, 7, [
        'value' => 0
    ]);

    switch ($user['apiType']) {
        case 'upwork':
            $config = new \Upwork\API\Config(
                array(
                    'consumerKey'       => $user['consumerKey'],
                    'consumerSecret'    => $user['consumerSecret'],
                    'verifySsl'         => false,
                    'accessToken'       => $user['accessToken'],
                    'accessSecret'      => $user['accessSecret'],
                    'debug'             => DEBUG_MODE,
                )
            );

            $client = new \Upwork\API\Client($config);
            $reports = new \Upwork\API\Routers\Reports\Time($client);

            $startDate = date('Y-m-d', strtotime('monday previous week'));
            $endDate = date('Y-m-d', strtotime('sunday this week'));
        
            $params = array(
                'tq' => "
                    SELECT worked_on, hours
                    WHERE worked_on >= '{$startDate}'
                        AND worked_on <= '{$endDate}'
                "
            );

            $timeInfo =  $reports->getByFreelancerFull($username, $params);

            //calculate week time
            $thisMonday = date('Ymd', strtotime('monday this week'));

            foreach ($timeInfo->table->rows as $row) {
                if ($row->c[0]->v < $thisMonday) {
                    $previousWeekTime += $row->c[1]->v;
                } else {
                    $thisWeekTime += $row->c[1]->v;
                    $dayOfWeek = date('N', strtotime($row->c[0]->v));
                    $thisWeekTimePerDay[$dayOfWeek-1] = ['value' => $row->c[1]->v + $thisWeekTimePerDay[$dayOfWeek-1]['value']];
                }
            }
            break;
                
        case 'toggl':
            //get previous week hours
            $startDate = date('Y-m-d', strtotime('monday previous week'));
            $weeklyReport = GetTogglWeeklyReport($user, $startDate);
            $previousWeekTime = ConvertMilisecondsToHours($weeklyReport->week_totals[7]);

            //get current week hours
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $weeklyReport = GetTogglWeeklyReport($user, $startDate);
            $thisWeekTime = ConvertMilisecondsToHours($weeklyReport->week_totals[7]);

            for ($i=0; $i < 7; $i++) { 
                $dayTimeMs = $weeklyReport->week_totals[$i];
                if (($i+1) == date('w')){
                    // If it is current day then get currently running time entry and count it
                    $dayTimeMs = $dayTimeMs + GetTogglCurrentTimeMs($user);
                }
                $dayTotal = ConvertMilisecondsToHours($dayTimeMs);
                $thisWeekTimePerDay[$i] = ['value' => $dayTotal];
            }
            break;

        default:
            continue 2;
    }

    $previousWeekData[] = [
        'label' => $user['name'],
        'value' => $previousWeekTime,
        'color' => $color
    ];

    $thisWeekData[] = [
        'label' => $user['name'],
        'value' => $thisWeekTime,
        'color' => $color
    ];
    $thisWeeDailyData[] = [
        'seriesname' => $user['name'],
        'data' => $thisWeekTimePerDay,
        'color' => $color
    ];
}

if (DEBUG_MODE) {
    exit;
}

$loader = new Twig_Loader_Filesystem(__DIR__.'/..');
$twig = new Twig_Environment($loader);

echo $twig->render('index.html.twig', array(
    'previousWeekData' => $previousWeekData,
    'thisWeekData' => $thisWeekData,
    'thisWeeDailyData' => $thisWeeDailyData
));
