<% if $Metrics %>
    <table>
        <thead>
        <tr>
            <th><%t ModuleMetrics.SITE "Site" %></th>
            <th><%t ModuleMetrics.MODULE_NAME "Module Name" %></th>
            <th><%t ModuleMetrics.IN_USE "In Use" %></th>
            <th><%t ModuleMetrics.USAGE_TYPE "Usage Type" %></th>
            <th><%t ModuleMetrics.RECORDS_FOUND "Record Count" %></th>
            <th><%t ModuleMetrics.FIELD_IN_USE "Field In Use" %></th>
            <th><%t ModuleMetrics.TABLE_IN_USE "Table In Use" %></th>
            <th><%t ModuleMetrics.LAST_EDITED "Last Edited" %></th>
        </tr>
        </thead>
        <tbody>
            <% loop $Metrics %>
            <tr>
                <td>$Site</td>
                <td>$ModuleName</td>
                <td>$InUse</td>
                <td>$UsageType</td>
                <td>$RecordsFound</td>
                <td>$FieldInUse</td>
                <td>$TableInUse</td>
                <td>$LastEdited</td>
            </tr>
            <% end_loop %>
        </tbody>
    </table>
<% else %>
    <p><%t ModuleMetrics.NO_DATA_FOUND "No data found" %></p>
<% end_if %>