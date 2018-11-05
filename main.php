<?php
include 'specificvars.php';
date_default_timezone_set("America/Los_Angeles");
/**
 * Preform a POST request on a specified url with the specified parameters
* @param string $url The url to query
* @param array $opts The url paramteters
* @return string The server's response
*/
function post_query_slack($url,$opts) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($opts));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    $data = curl_exec($curl);
    curl_close($curl);
    return $data;
}
function json_post_query_slack($url,$token,$encoded_data) {
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded_data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer '.$token,
        'Content-Length: '.strlen($encoded_data)
    ));
    $data = curl_exec($curl);
    curl_close($curl);
    return $data;
}
function open_im($user) {
    global $bot_token;
    $response = json_decode(post_query_slack("https://slack.com/api/im.open", array("token"=> $bot_token, "user"=> $user)), true);
    if (isset($response["ok"]) && $response["ok"]) {
        return $response["channel"]["id"];
    } else {
        writeToLog("Failed to open im: ".json_encode($response), "events");
        return false;
    }
}
function postMessage($channel, $message) {
    global $bot_token;
    $message["channel"] = $channel;
    json_post_query_slack("https://slack.com/api/chat.postMessage",$bot_token,json_encode($message));
}
function writeToLog($string, $log) {
	file_put_contents("./".$log.".log", date("d-m-Y_h:i:s")."-- ".$string."\r\n", FILE_APPEND);
}
function stopTimeout() {
    ignore_user_abort(true);
    ob_start();
    header('Connection: close');
    header('Content-Length: '.ob_get_length());
    http_response_code(200);
    ob_end_flush();
    flush();
}
function verifySlack() {
    global $slack_signing_secret;
    $headers = getallheaders();
    if(! (isset($headers['X-Slack-Request-Timestamp']) && isset($headers['X-Slack-Signature']))) {
        writeToLog("Verification failed, Invalid headers","events");
        die();
    }
    if(abs(time() - $headers['X-Slack-Request-Timestamp']) > 60 * 5) {
        writeToLog("Verification failed, request too old","events");
        die();
    }
    $signature = 'v0:' . $headers['X-Slack-Request-Timestamp'] . ":" . file_get_contents('php://input');
    $signature_hashed = 'v0=' . hash_hmac('sha256', $signature, $slack_signing_secret);
    return hash_equals($signature_hashed, $headers['X-Slack-Signature']);
  }
if($_SERVER["REQUEST_METHOD"] == "POST" && verifySlack()) {
    $headers = getallheaders();
    if(isset($headers["X-Slack-Retry-Reason"])) {
        writeToLog("Slack retry because ".$headers["X-Slack-Retry-Reason"],"events");
    }
    $data = json_decode(file_get_contents("php://input"), true);
    switch($data["type"]) {
        case "url_verification":
            writeToLog("Slack url verification","events");
            header("Content-type: application/json");
            echo(json_encode(array("challenge"=>$data["challenge"])));
            break;
        case "event_callback":
            stopTimeout();
            $event = $data["event"];
            $message = json_decode(file_get_contents("message.json"), true);
            switch($event["type"]) {
                case "team_join":
                    writeToLog("New user joined with id ".$event["user"]["id"],"events");
                    $channel = open_im($event["user"]["id"]);
                    postMessage($channel,$message);
                    break;
                case "message":
                    writeToLog("Recieved ".$event["channel_type"]." in channel ".$event["channel"]." from ".$event["user"], "events");
                    if($event["user"] == "") {
                        exit();
                    }
                    if($event["channel_type"] == "im") {
                        if(strpos($event["text"], "repeat") !== false) {
                            writeToLog("Sending message in ".$event["channel"], "events");
                            postMessage($event["channel"],$message);
                        }
                    }
                    break;
                default:
                    break;
            }
        default:
            break;
    }
} else {
    echo("You're not supposed to be here");
}
?>