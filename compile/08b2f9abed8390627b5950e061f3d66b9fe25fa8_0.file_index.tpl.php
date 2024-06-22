<?php
/* Smarty version 5.3.1, created on 2024-06-22 12:33:24
  from 'file:index.tpl' */

/* @var \Smarty\Template $_smarty_tpl */
if ($_smarty_tpl->getCompiled()->isFresh($_smarty_tpl, array (
  'version' => '5.3.1',
  'unifunc' => 'content_66769a644fc850_79967083',
  'has_nocache_code' => false,
  'file_dependency' => 
  array (
    '08b2f9abed8390627b5950e061f3d66b9fe25fa8' => 
    array (
      0 => 'index.tpl',
      1 => 1719048802,
      2 => 'file',
    ),
  ),
  'includes' => 
  array (
  ),
))) {
function content_66769a644fc850_79967083 (\Smarty\Template $_smarty_tpl) {
$_smarty_current_dir = 'E:\\OSPanel\\home\\notes.loc\\app\\views';
?><h1>Hello <?php echo htmlspecialchars((string)$_smarty_tpl->getValue('name'), ENT_QUOTES, 'UTF-8', true);?>
, welcome to Smarty!</h1>
<p><?php echo $_smarty_tpl->getValue('names');?>
</p><?php }
}
