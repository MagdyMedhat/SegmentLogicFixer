<?php

/*
 *  Script is maintained by Magdi Medhat (magdi.medhat@aurea.com) - 30/10/2016
 *  DEV-16073, fix segments logic using an update API call to filter/update after parsing the RULE_XML db field.
 *  Algorithm:
 *   => take input: SITE_ID, MAILING_LIST_ID, RULE_ID (a segment which was created between 23 August and 31 August)
 *   => Fetch record from Mailing_list_rule_table, parse RULE_XML field.
 *   => Parse the RULE_XML into two parts:
 *       1) Create the terms equivalent parameter lines and store them in a terms Map.
 *       2) Parse the logic tag and insert correct Logic conjunctions inside term lines.
 *   => Put together all parameters and call the API filter/update.
*/

libxml_use_internal_errors(true);

function parseRuleXML($rule_xml)
{
    $xml = simplexml_load_string($rule_xml);
    if ($xml === false)
    {
        $errors = "Failed loading XML: ";
        foreach (libxml_get_errors() as $error)
            $errors .= "\r\n" . $error->message;
        echo $errors;
        exit();
    }
    else
        return $xml;
}

function expandLogicNode($xml_node, &$group_number, &$terms_lookup)
{
    if ($xml_node->getName() == "operand")
    {
        //echo "</br> TRACE: operand";
        $key = $xml_node['tagref'];
        $rule = $terms_lookup["$key"];

        return $rule;
    }
    else if ($xml_node->getName() == "operator")
    {
        //echo "</br> TRACE: operator";
        $type = $xml_node['type'];
        $rule = "";
        $counter = 0;
        foreach ($xml_node->children() as $inner_tag)
        {
            $tag = expandLogicNode($inner_tag, $group_number, $terms_lookup);

            if ($counter > 0)
            {
                $index = strpos($tag, ">");

                if($tag[$index - 1] == '/')
                    $index--;

                $type = strtoupper($type);
                $tag = substr_replace($tag, " logic=\"$type\" ", $index, 0);
            }

            $rule .= "\n\r" . $tag;
            $counter++;
        }
        return $rule;
    }

    else if ($xml_node->getName() == "group")
    {
        //echo "</br> TRACE: group";
        $group_number++;
        $rule = "";
        foreach ($xml_node->children() as $inner_tag)
        {
            $rule .= "\n\r" . expandLogicNode($inner_tag, $group_number, $terms_lookup);
        }

        if ($group_number > 0)
        {
            $index = strpos($rule, ">");

            if ($rule[$index - 1] == '/')
                $index--;

            $rule = substr_replace($rule, " group=\"$group_number\" ", $index, 0);
        }
        return $rule;
    }
    else if ($xml_node->getName() == "not")
    {
        //echo "</br> TRACE: not";

        $child_node = $xml_node->Children();
        $child_node = $child_node[0];
        $rule = expandLogicNode($child_node, $group_number, $terms_lookup);
        $start_index = strpos($rule, "id=") + 4;
        $end_index = strpos($rule, "\"", $start_index);
        $id = substr($rule, $start_index, $end_index - $start_index);
        $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">d-$id</DATA>";
        return $rule;
    }

}

function parseRuleTerms($xml)
{
    $terms_lookup = array();

    if (isset($xml->term) && count($xml->term) > 0) {
        foreach ($xml->term as $term) {
            //tag name which will be used to subs. inside the logic tag.
            $tag = $term['tag'];

            if (isset($term->comparison)) {
                $type = $term->comparison['type']; //null - any - equals - between - lt - gt - le - ge
                $id = $term->comparison->field['id']; //the demographic number

                $rule = "";

                if ($type == "null") {
                    $rule = "<DATA type=\"demographic\" id=\"$id\"/>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"blank\">d-$id</DATA>";
                }

                if ($type == "any") {
                    $rule = "<DATA type=\"demographic\" id=\"$id\"/>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">d-$id</DATA>";
                } else if ($type == "equals") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">$temp->constant</DATA>";
                } else if ($type == "between") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant[0] . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant[1] . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"between\">d-$id</DATA>";
                } else if ($type == "lt") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"lessthan\">d-$id</DATA>";
                } else if ($type == "gt") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"greaterthan\">d-$id</DATA>";
                } else if ($type == "le") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"greaterthan\">d-$id</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">d-$id</DATA>";
                } else if ($type == "ge") {
                    $temp = $term->comparison;
                    $rule = "<DATA type=\"demographic\" id=\"$id\">" . $temp->constant . "</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"lessthan\">d-$id</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">d-$id</DATA>";
                }
            } else if (isset($term->joindate)) {
                $type = $term->joindate['type']; //before - after
                $date = $term->joindate['date']; //the date value

                if ($type == "before") {
                    $rule = "<DATA type=\"activity\" id=\"join\">$date:b</DATA>";
                } else if ($type == "after") {
                    $rule = "<DATA type=\"activity\" id=\"join\">$date:a</DATA>";
                }
            } else if (isset($term->activity)) {
                $type = $term->activity['type']; //sent - not sent - clicked - not clicked
                $days = $term->activity['daysback']; //number of days

                if ($type == "sent") {
                    $rule = "<DATA type=\"activity\" id=\"message\">sent</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"days\" value=\"$days\">message</DATA>";

                } else if ($type == "not sent") {
                    $rule = "<DATA type=\"activity\" id=\"message\">sent</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">message</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"days\" value=\"$days\">message</DATA>";
                } else if ($type == "clicked") {
                    $rule = "<DATA type=\"activity\" id=\"message\">sentclicked</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"days\" value=\"$days\">message</DATA>";
                } else if ($type == "not clicked") {
                    $rule = "<DATA type=\"activity\" id=\"message\">sentclicked</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">message</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"days\" value=\"$days\">message</DATA>";
                }
            } else if (isset($term->message)) {
                $type = $term->message['type']; //sent - not sent - openedclicked - openednotclicked
                $id = $term->message->field['id']; //message id

                if ($type == "sent") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">sent</DATA>";
                } else if ($type == "not sent") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">sent</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">a-$id</DATA>";
                } else if ($type == "openedclicked") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">openedclicked</DATA>";
                } else if ($type == "openednotclicked") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">openedclicked</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"inverse\">a-$id</DATA>";
                }
            } else if (isset($term->messageopens)) {
                $type = $term->messageopens['type']; //at least - less than
                $id = $term->messageopens->field['id']; //message id
                $freq = $term->messageopens->field['frequency']; // opens count

                if ($type == "at least") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">opened</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"opened\">a-$id:$freq</DATA>";
                } else if ($type == "less than") {
                    $rule = "<DATA type=\"activity\" id=\"$id\">opened</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"inverse\" id=\"opened\">a-$id</DATA>";
                    $rule .= "\n\r" . "<DATA type=\"extra\" id=\"opened\">a-$id:$freq</DATA>";
                }
            } else if (isset($term->clickactivity)) {
                //$type = $term->messageopens['type']; //at least - less than
                //$id = $term->messageopens->field['id']; //message id
                //$freq = $term->messageopens->field['frequency']; // opens count

                /*
                 * clicked any links between these days from these messages:
                 *    <term tag="Operand0">
                 *      <clickactivity type="regular" url="any" starttime="2016-10-16" endtime="2016-10-18">
                 *         <messageid id="527877"/>
                 *          <messageid id="528029"/>
                 *          <messageid id="528027"/>
                 *      </clickactivity>
                 *  </term>
                 *
                 * */

                /*
                 *  clicked these links between these days from these messages:
                 * <term tag="Operand0">
                      <clickactivity type="regular" url="http://www.facebook.com/sharer.php?u=http://www.elabs13.com/functions/social_create.html?socid=&lt;MLM_SOCID>&amp;hq_e=el&amp;hq_m=&lt;MLM_MID>&amp;hq_l=1&amp;hq_v=&lt;MLM_UNIQUEID>" starttime="2016-05-01" endtime="2016-10-18">
                        <messageid id="527877"/>
                        <messageid id="528040"/>
                        <messageid id="527875"/>
                        <messageid id="527874"/>
                        <messageid id="525859"/>
                        <messageid id="520639"/>
                        <messageid id="520638"/>
                        <messageid id="520637"/>
                      </clickactivity>
                    </term>
                 *
                *    <DATA type="activity" id="message" logic="AND">clickedaggregate</DATA>  //who did click any link from X messages between DATE1 and DATE2
                  <DATA type="extra" id="message_ids" value="527877,528029,528027">message</DATA>
                  <DATA type="extra" id="start_date">2016-10-16</DATA>
                  <DATA type="extra" id="end_date">2016-10-17</DATA>
                 *  */

            } else if (isset($term->messageclicks)) {
                /*
                  * who were sent this message and did click this link at least 2 times
                  *     <term tag="Operand0">
                  *          <messageclicks id="527877">
                  *         <click uniqueid="9fb3a4abb0" frequency="2"/>
                  *     </messageclicks>
                  **/
            }

            $terms_lookup["$tag"] = $rule;
        }
    }

    return $terms_lookup;
}

function parseRuleLogic($xml)
{
    if (isset($xml->logic))
    {
        $inner_tag = $xml->logic->Children();
        $inner_tag = $inner_tag[0];
        $group_number = -1;
        $terms_lookup = parseRuleTerms($xml);
        $rule_string = expandLogicNode($inner_tag, $group_number, $terms_lookup);
        return $rule_string;
    }
}

if ($submit == "Submit")
{
    require('local_includes.inc');
    require(GlobalConfig::getConfig('emaillabs_libs').'/db/open_db.inc');

    if (!is_numeric($site_id) || !is_numeric($mailing_list_id) || !is_numeric($rule_id))
    {
        echo "all input values must be numbers \n\r";
        exit ();
    }

    $sqlcall = "select RULE_NAME, XML_RULE from Mailing_List_Rule_Table " .
        "where RULE_ID ='" . $rule_id . "' " .
        "and SITE_ID = '$site_id' " .
        "and MAILING_LIST_ID='$mailing_list_id'";

    $result = mysql_query($sqlcall, $dbid);
    list($rule_name, $xml_rule) = mysql_fetch_array($result);

    if ($xml_rule == "NULL")
    {
        echo "RULE_ID: $rule_id \n \r RULE_NAME: $rule_name \n\r Already fixed or Relational Attribute wasn't used here. \n \r";
        exit();
    }

    //Form the API call and parameters
    $type = "filter";
    $activity = "update";
    $input = "<DATASET>
            <SITE_ID>$site_id</SITE_ID>
            <MLID>$mailing_list_id</MLID>
            <DATA type=\"id\">$rule_id</DATA>
            <DATA type=\"name\">$rule_name</DATA>
            <DATA type=\"segment_validation\">true</DATA>";

    $xml = parseRuleXML($xml_rule);
    $rule_parameters = parseRuleLogic($xml);
    $input .= $rule_parameters;
    $input .="</DATASET>";

//    $input = str_replace('&', '&amp;', $input);
//    $input = str_replace('<', '&lt;', $input);
//    echo '<pre>' . $input . '</pre>';
//    exit();

    $sqlcall = "select VALUE from Farm_Config_Table where name='API_KEY'";
    $result = mysql_query($sqlcall, $dbid);
    list($key) = mysql_fetch_array($result);

    $url = GlobalConfig::getConfig('sitefunctionsurl') . "/API/mailing_list.html";
    $input=stripslashes($input);
    $postvars = "type=$type&activity=$activity&input=$input&key=$key";

    header("Content-Type: text/xml\n\n");
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postvars);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    $result = curl_exec($ch);

    //for monitoring.
    //$elabs_farm = GlobalConfig::getConfig('sitefunctionsurl');
    //$this->mail = new html_mime_mail("", "", "", $site_id);
    //$this->mail->body = $output;
    //$this->mail->send("Magdi Medhat", "magdi.medhat@aurea.com", "DEV-16073 Fix Script", "noreply@lyris.com", "Script Called from $elabs_farm", "", "", "");

    echo $result;
}

else
{
    ?>
    <html>
    <head><title>DEV-16073 Segments Logic Fix</title>
    </head>
    <body bgcolor="#ffffff">
    <center>
        <h2>Segments Logic Fix Script</h2>
        <font size="2" face="arial,helvetica,verdana">
            Please double check the IDs before you click submit.</font><br>
    </center>
    <form name="api" method="post" action="">
        <table border="0">
            <tr><td>SITE ID:</td><td><input name="site_id" id="site_id" type="text"></tr>
            <tr><td>MAILING LIST ID:</td><td><input name="mailing_list_id" id="mailing_list_id" type="text"></tr>
            <tr><td>RULE ID:</td><td><input name="rule_id" id="rule_id" type="text"></tr>
            <tr><td></td><td><input type="submit" name="submit" value="Submit"></td></tr>
        </table>
    </form>
    </body>
    </html>
<?
}
?>
