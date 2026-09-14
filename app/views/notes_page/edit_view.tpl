{extends file="core/base.tpl"}
{block name=title}Блокнот: {$note.notename|escape}{/block}
{block name=body}
{include file="notes_page/editor-013.tpl"}
<script src="{$base_url}/assets/js/notes-editor-013.js" defer></script>
{/block}