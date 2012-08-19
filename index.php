<?php
require_once 'inc/EnnoAutoPost.php';
$htmlString = $_SERVER['KMVAR_blogString'];
$identifier = $_SERVER['KMVAR_blogIdentifier'];
$obj = new EnnoAutoPost($htmlString, $identifier);
$obj->setMetadata();
$obj->replaceCode();
$obj->replaceImageMarkup();
echo $obj->createPost();