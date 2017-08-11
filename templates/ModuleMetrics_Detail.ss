<html>
<head>

</head>
<body>
    <% if $List.Count %>
    <ul>
        <% loop $List %>
            <li></li>
        <% end_loop %>
    </ul>
    <% end_if %>
</body>
</html>
