<?php
/*echo "<pre>";
$name=$_POST['form_name'];
$form[$name] = $_POST;
print_r($form);
echo "</pre>";*/
$form = file_get_contents($path.'html/create-form.html');
$out->addHTML($form);
?>
