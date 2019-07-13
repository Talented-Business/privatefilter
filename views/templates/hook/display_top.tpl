<form action="{$search_controller_url}" method="get">
  <div class="filter">
    <select name="make" id="filter_make">
      {foreach from=$makes item=value key=index}
        <option value="{$index}" {if $index==$make }selected{/if}>{$value}</option>
      {/foreach}
    </select>
    <select name="model" {if $make==''}disabled{/if}  id="filter_model">
      {foreach from=$models item=value key=index}
        <option value="{$index}" {if $index==$model}selected{/if}>{$value}</option>
      {/foreach}
    </select>
    <select name="year" {if $model==''}disabled{/if}  id="filter_year">
      {foreach from=$years item=value key=index}
        <option value="{$index}" {if $index==$year}selected{/if}>{$value}</option>
      {/foreach}
    </select>
    <button class='btn btn-default result' type="submit">show</button>
  </div>
</form>
<script>
  let filter_features = {$filter_features|json_encode nofilter}; 
  let sub_features = {$sub_features|json_encode nofilter}; 
</script>