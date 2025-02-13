<span class="readonly typography" id="$ID">
	<% if $FormattedValue %>$FormattedValue<% else %><i>(not set)</i><% end_if %>
</span>
<% if $IncludeHiddenField %>
	<input type="hidden" name="$Name.ATT" value="$FormattedValueEntities.RAW" />
<% end_if %>
