# Envío automático de encuestas

El endpoint protegido procesa las RH Experiences del día actual a partir de las 17:00, usando la zona horaria `America/Merida`:

```text
GET /api/surveys/send-pending?secret=VALOR_DE_SURVEY_CRON_SECRET
```

Configura `SURVEY_CRON_SECRET` en el entorno del backend. Como el servidor de cPanel usa MST (una hora detrás de Mérida), las 17:00 de Mérida corresponden a las 16:00 del servidor.

Para recuperar automáticamente envíos interrumpidos por límites del hosting o fallos temporales de SMTP, ejecuta el proceso cada 15 minutos desde las 16:00 hasta las 22:59 MST:

```cron
*/15 16-22 * * * curl --fail --silent --show-error "https://API_HOST/api/surveys/send-pending?secret=VALOR_DE_SURVEY_CRON_SECRET" >> "$HOME/survey-cron.log" 2>&1
```

El primer intento ocurre a las 17:00 de Mérida. Las siguientes ejecuciones omiten los correos registrados en `survey_emails` y reintentan solamente los pendientes. El archivo `survey-cron.log` conserva los errores de `curl` para diagnóstico.
