from pathlib import Path
block = '\n    # Dialer call-events WebSocket (webhook push → browser).\n    location /communications-ws/ {\n        proxy_pass http://127.0.0.1:8787/;\n        proxy_http_version 1.1;\n        proxy_buffering off;\n        proxy_set_header Upgrade $http_upgrade;\n        proxy_set_header Connection $connection_upgrade;\n        proxy_set_header Host $host;\n        proxy_set_header X-Real-IP $remote_addr;\n        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;\n        proxy_set_header X-Forwarded-Proto $scheme;\n        proxy_read_timeout 86400s;\n        proxy_send_timeout 86400s;\n    }\n'
paths = list(Path('/etc/nginx/sites-enabled').glob('*'))
for p in paths:
    text = p.read_text()
    if 'communications-ws' in text:
        print('already', p)
        break
    if 'location /morpheus-ws/' in text:
        text = text.replace('location /morpheus-ws/', block + '\n    location /morpheus-ws/', 1)
        p.write_text(text)
        print('patched', p)
        break
else:
    print('no_nginx_site_patched')
