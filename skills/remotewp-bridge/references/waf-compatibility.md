# RemoteWP utility: WAF and security-plugin compatibility

Use this playbook when a RemoteWP request is blocked by Wordfence,
ModSecurity/cPanel, Cloudflare or another hosting firewall.

## Safe diagnostic workflow

1. Record the RemoteWP endpoint, HTTP method, status and `X-RemoteWP-Request-ID`.
   The plugin returns this header for RemoteWP REST responses, including
   authentication and permission failures that reach WordPress.
2. Inspect the Wordfence or ModSecurity audit log for the matching request ID, timestamp and rule ID.
3. Reproduce only the approved action with the smallest payload; never include a license key or private file contents in a support log.
4. If the request is a false positive, create a narrow exception for the exact endpoint/parameter/rule and test it.
5. Re-enable the firewall's normal protection mode and verify the endpoint again.

If the firewall rejects the request before WordPress runs, the plugin cannot
add a response header. In that case correlate by timestamp, source IP and
the firewall's own request/rule ID; do not infer that a missing RemoteWP ID
means the request was accepted.

## Wordfence

Learning Mode may be used temporarily while reproducing a known safe action.
Review the generated allowlist entry, then return the firewall to Enabled and
Protecting. Do not allowlist the whole site or a dynamic client IP. IP
allowlisting that bypasses all rules is appropriate only for a permanent,
trusted static IP and an administrator-approved setup.

## ModSecurity/cPanel/Cloudflare

The hosting administrator must apply the smallest rule or route exception in
the provider's supported interface. Prefer an exception tied to the exact
RemoteWP route and rule ID. Do not disable ModSecurity globally and do not
change firewall configuration automatically from the WordPress plugin.

## Transport rule

Base64 is not a security bypass. After the normal route and a narrow,
administrator-approved exception have been tried, use an encoded transport
only as a last resort when the site context explicitly advertises and
authorizes that documented RemoteWP dispatcher. Otherwise stop, report the
request ID and ask the administrator to allow the safe request or correct the
false-positive rule.
