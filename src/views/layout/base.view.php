<?php
    global $_yield, $_section, $_include;

?>
<!DOCTYPE html>
<html>
<head>
    <?php
        $_include('header');
    ?>

</head>

<body>
<?php


    $_yield('content')

?>
</body>
</html>



