<!DOCTYPE html>
<html lang="fa">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فرم ارسال پست</title>
    <link rel="stylesheet" href="https://ahangban.ir/bot/files/styles.css">
</head>
<body>
    <div class="background"></div>
    <div class="form-container">
	 <?php if(isset($_GET['q']) && $_GET['q'] != null) echo '<div class="alert-box"><p><a href="'.$_GET['q'].'">'.$_GET['t'].'</a></p></div>'; ?> 
        <form action="https://ahangban.ir/bot/manual.php" method="post">
            <label for="link">: لینک پست </label>
            <input type="url" id="link" name="link" placeholder="https://example.com" required>
            <button type="submit">ارسال</button>
        </form>
    </div>
</body>
</html>
