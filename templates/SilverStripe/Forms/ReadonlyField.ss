<span id="$ID" <% if $extraClass %>class="$extraClass"<% end_if %>>
	$FormattedValue
</span>
<% if $IncludeHiddenField %>
	<input $getAttributesHTML("id", "type") id="hidden-{$ID}" type="hidden" />
<% end_if %>
