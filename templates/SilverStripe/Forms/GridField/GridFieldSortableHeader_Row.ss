<tr class="sortable-header">
	<% loop $Fields %>
		<th class="main col-$getName"
			<% if $Me.hasClass("ss-gridfield-sorted-asc") %>
				aria-sort="ascending"
			<% else_if $Me.hasClass("ss-gridfield-sorted-desc") %>
				aria-sort="descending"
			<% end_if %>
		>$Field</th>
	<% end_loop %>
</tr>
