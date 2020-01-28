<?php 

require 'lib/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;//https://phpspreadsheet.readthedocs.io/en/latest/topics/reading-and-writing-to-file/#excel-5-biff-file-format

define("TOKEN" ,"Ваш токен");//токен пользователя (см. Директ API)

function curl_get($ch,$postdata){

    $data_string = json_encode($postdata, JSON_UNESCAPED_UNICODE);
        
    curl_setopt($ch,CURLOPT_HTTPHEADER, array(
        'POST /v4/json/ HTTP/1.1',
        'Host: api-sandbox.direct.yandex.com',
        'Authorization: Bearer ' . TOKEN,
        'Accept-Language: ru',
        'Content-Type: application/json; charset=utf-8'
    )); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
    
    // для отладки
    // $fOut = fopen($_SERVER["DOCUMENT_ROOT"].'/'.'curl_out.txt', "w" );
    // curl_setopt ($ch, CURLOPT_VERBOSE, 1);
    // curl_setopt ($ch, CURLOPT_STDERR, $fOut );

    $data = curl_exec($ch);
    $data2 = json_decode($data, true);
    return $data2;
}


//запрос на формирование отчета по выбраным словам
function formReport($ch, $wordsArr, $area){

    $postdata = array(
        "method" => "CreateNewWordstatReport",
        "param" => [
            "Phrases" => $wordsArr,
            "GeoID"=> [$area]
        ],
        "locale" => "ru",
        "token" => TOKEN
    );
    //print_r($postdata);
    $data = curl_get($ch, $postdata);
}

//Проверка готовности отчета
function checkReport($ch){

    $postdata = array(
        "method" => "GetWordstatReportList",
        "locale" => "ru",
        "token" => TOKEN
    );
    $reportsArr = array();
    $data = curl_get($ch, $postdata);

    foreach ($data["data"] as $i => $report){
        $reportsArr[$report["ReportID"]] = $report["StatusReport"];
    }
    return $reportsArr;

}

//Загрузка отчета по выбраным словам
function downloadReport($ch,$reportNum){

    $postdata = array(
        "method" => "GetWordstatReport",
        "param" => $reportNum,
        "locale" => "ru",
        "token" => TOKEN
    );

    return curl_get($ch, $postdata);
}

//удаление отчета с сервера Яндекса
function deleteReport($ch,$reportNum){

    $postdata = array(
        "method" => "DeleteWordstatReport",
        "param" => $reportNum,
        "locale" => "ru",
        "token" => TOKEN
    );

    $html = curl_get($ch, $postdata);
}

//удаление всех отчетов с сервера Яндекса
function deleteAllReport($ch,$reportsArr){
    foreach ($reportsArr as $reportNum => $status) {
        deleteReport($ch,$reportNum);
    }
}

//добавление результатов отчетов по словам в массив 
function getWordsUseCount($data,$WordsUseCount){
    foreach ($data["data"] as $i => $word) {
        foreach ($word['SearchedWith'] as $j => $option) {
            if ($option['Phrase']==$word['Phrase']) {
                $WordsUseCount[$option['Phrase']]=$option['Shows'];
                break;
            }
        }
    }
    return $WordsUseCount;

}

//генерация файла
function createFile($WordsUseCount){
    
    header ( "Expires: Mon, 1 Apr 1974 05:00:00 GMT" );
     header ( "Last-Modified: " . gmdate("D,d M YH:i:s") . " GMT" );
     header ( "Cache-Control: no-cache, must-revalidate" );
     header ( "Pragma: no-cache" );
     header ( "Content-type: application/vnd.ms-excel" );
     header ( "Content-Disposition: attachment; filename=matrix.xls" );
 
    //
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    // Записываем данные в файл

    $counter = 1;
    foreach ($WordsUseCount as $key => $value) {
        $sheet->setCellValue("A".$counter, $key);
        $sheet->setCellValue("B".$counter, $value);
        $counter++;
    } 

    $writer = new Xlsx($spreadsheet);
    $writer->save('Results/Result.xlsx');
}

$ch = curl_init('https://api-sandbox.direct.yandex.com/v4/json/');//https://api-sandbox.direct.yandex.com/v4/json/ --- так как работа в песочнице, должно быть https://api.direct.yandex.com/v4/json/
$text = $_POST['keywords'];
$area =  $_POST['area'];
$keywords = preg_split("/[\n]/", $text);
$wordCounter = 0;
$requestCounter = 0;

$WordsUseCount = array();
$wordsArr=array();

switch ($area) {
    case 'all':
        $area = 0;
        break;
    case 'moscow':
        $area = 213;
        break;
    case 'dubna':
        $area = 215;
        break;
    default:
        $area = 0;
        break;
}

$reportsArr = checkReport($ch);
deleteAllReport($ch,$reportsArr);


for ($i=0; $i < count($keywords); $i++) { 
    //необходима проверка правильности указания минус-фраз
    //необходимо отсеевать стоп-слова 
    // "{"error_code":71,"error_str":"Параметры запроса указаны неверно","error_detail":"Ключевая фраза не может состоять только из стоп-слов: союзов, предлогов, частиц \"когда\""}"

    array_push($wordsArr, $keywords[$i]);
    //$wordCounter++;
    if (count($wordsArr)==10) {
        //$wordCounter=0;
        formReport($ch, $wordsArr, $area);//отправка запроса на формирование отчета по словам (максимум на 10 слов, см. API Директа)
        $wordsArr=array();
        $requestCounter++;
        while ($requestCounter==5) {
            sleep(15);//задержка на отправку запроса проверки готовности отчетов
            $reportsArr = checkReport($ch);
            if (count($reportsArr)!=0) {
                foreach ($reportsArr as $reportNum => $status) {
                    if ($status == "Done") {
                        $data = downloadReport($ch,$reportNum);
                        $WordsUseCount = getWordsUseCount($data,$WordsUseCount);
                        $requestCounter--;
                        deleteReport($ch,$reportNum);
                    }
                    //нужна проверка на ошибку выполнения отчета
                    //  if ($status=="Failed") {
                    //      formReport(...
                    //  } 
                }
            }
            $reportsArr = checkReport($ch);
            $requestCounter = 0;
            foreach ($reportsArr as $reportNum => $status) {
                if ($status == "Done"){
                    $requestCounter++;
                }
            }
        }
    }
}

if (count($wordsArr)!=0) {
    formReport($ch, $wordsArr, $area);
}
while ($requestCounter>0) {
    sleep(15);//задержка на отправку запроса проверки готовности отчетов
    $reportsArr = checkReport($ch);
    if (count($reportsArr)!=0) {
        $newReportArr = array();
        foreach ($reportsArr as $reportNum => $status) {
            if ($status == "Done") {
                $data = downloadReport($ch,$reportNum);
                $WordsUseCount = getWordsUseCount($data,$WordsUseCount);
                $requestCounter--;
                deleteReport($ch,$reportNum);
            }
            //нужна проверка на ошибку выполнения отчета 
            elseif ($status=="Failed") {
                deleteReport($ch,$reportNum);
            } 
        }
    }
}
createFile($WordsUseCount);

