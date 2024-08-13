<div class="form-input_row" id="{$fieldBlockName}">
    <label for="{$fieldName}" class="form-input_label">{$fieldTitle} {if $required}*{/if}</label>
    <input class="form-input_input"
        type="{$fieldType}"
        name="{$fieldName}"
        id="{$fieldId}"
        change="{$fieldFunc}"
        value="{$fieldValue}"
        {if $required}required{/if}>
    <span id="er"></span>
</div>
