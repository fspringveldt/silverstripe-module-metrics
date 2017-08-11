<html>
<head>
    <style>
        div.limit-height {
            overflow: auto;
            height: 2em;
        }
    </style>
</head>
<body>
<table>
    <thead>
    <tr>
        <th>No.</th>
        <th>Name</th>
        <th>Path</th>
        <th>Classes</th>
        <th>DataObjects</th>
    </tr>
    </thead>
    <tbody>
        <% loop $Modules %>
        <tr>
            <td>$Pos</td>
            <td>$Module.Name</td>
            <td>$Module.Path</td>
            <td>
                <a href="$Top.Link('getModulesWithDataManipulations')">View Classes</a>
            </td>
            <td>
                <a href="$Top.Link('')">View DataObjects</a>
            </td>
        </tr>
        <% end_loop %>
    </tbody>
</table>
</body>
</html>
