<?php
$p = $_POST['p'];
$artist = $_POST['artist'];
$song = $_POST['song'];
$my_post_content = "
<h1 style=\"text-align: center;\"><span style=\"color: #808080;\">دانلود آهنگ جدید {$artist} به نام {$song}</span></h1>
<h2 style=\"text-align: center;\"><span style=\"color: #008000;\">با موفقیت در سایت ثبت شد</span></h2>
";
print_r($my_post_content);


$ch = curl_init("https://ahangban.ir/bot/manual.php");
$data = array('link' => $p );
curl_setopt($ch, CURLOPT_POST,1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_exec($ch);

?>