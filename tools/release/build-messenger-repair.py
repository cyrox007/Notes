import argparse,hashlib,json,pathlib,zipfile
parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);args=parser.parse_args()
root=pathlib.Path(__file__).resolve().parents[2]
files=['modules/messenger/views/style.css','modules/messenger/views/visual-refresh.css','modules/messenger/views/script.js','modules/messenger/views/voice.js','modules/messenger/views/voice.css','modules/messenger/views/calls.js','modules/messenger/services/MessengerVoiceService.php','modules/messenger/services/MessengerMediaService.php','modules/messenger/handlers/MessengerCrypto.php','assets/css/local-transcription.css','assets/css/messenger-connection-ux.css','assets/js/messenger-connection-ux.js']
payload={n:(root/n).read_bytes() for n in files}
payload['payload.json']=json.dumps({n:hashlib.sha256(b).hexdigest() for n,b in payload.items()},indent=2).encode()
payload['repair-messenger.php']=(root/'tools/release/repair-messenger-1.0.16.php').read_bytes()
payload['README.md']='''# Messenger repair r9 for installed Workspace Organizer 1.0.16

Extract outside the site. Finish active updates before applying. Run PHP 8.1+:

    php repair-messenger.php --yes --root=/absolute/path/to/workspace

The installer verifies payload checksums, backs up affected files outside the application and restores replaced files if installation fails. It preserves the version, .env, encryption keys and messages. Existing custom .htaccess is preserved; only camera=() is changed to camera=(self), if present.

Then run from the installed site directory:

    php bin/migrate.php
    php bin/migrate.php --status

Final status must show zero pending migrations. Reload both browsers with Ctrl+F5. Use trusted HTTPS for camera and microphone.

Fixes: neutral connection status for the first five seconds, immediate offline/session notices, bounded header status without overlapping actions; per-tab/per-user active dialog, draft and scroll restoration after reload; stable message/video nodes during receipt refresh and typing; compact Reply/Reaction/More toolbar above messages; request-local Apache storage/key lookup, compact responsive voice/transcription card, live microphone meter, camera permission policy, audio fallback if a camera is busy/unavailable. The receiving participant can continue a video call without their camera. A hardware camera may remain exclusive to one browser: use two devices to test two-way video, or release the other browser camera and start a new call. No external services or runtime libraries added.

Unknown audio duration is displayed as a dash rather than a false zero. Existing files recorded into an old fallback directory may require separate recovery; the patch does not automatically move user media. Browser proxy and local TLS certificate configuration are machine-specific and not included. This is not a signed offline update package. Do not copy the complete archive over the application.
'''.encode()
payload['SHA256SUMS']=''.join(f'{hashlib.sha256(b).hexdigest()}  {n}\n' for n,b in sorted(payload.items())).encode()
out=pathlib.Path(args.output);out.parent.mkdir(parents=True,exist_ok=True)
with zipfile.ZipFile(out,'w',zipfile.ZIP_DEFLATED) as z:
 for n,b in sorted(payload.items()):z.writestr(n,b)
pathlib.Path(str(out)+'.sha256').write_text(hashlib.sha256(out.read_bytes()).hexdigest()+'  '+out.name+'\n')
print('Built',out.name)
