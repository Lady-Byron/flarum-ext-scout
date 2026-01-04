import app from 'flarum/admin/app';

app.initializers.add('clarkwinkelmann-scout', () => {
  app.extensionData.for('clarkwinkelmann-scout')
    .registerSetting({
      type: 'select',
      setting: 'clarkwinkelmann-scout.driver',
      options: {
        null: app.translator.trans('clarkwinkelmann-scout.admin.setting.driverDisabled'),
        elasticsearch: 'Elasticsearch',
      },
      default: 'null',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.driver'),
    })
    .registerSetting({
      type: 'text',
      setting: 'clarkwinkelmann-scout.prefix',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.prefix'),
      help: app.translator.trans('clarkwinkelmann-scout.admin.setting.prefixHelp'),
    })
    .registerSetting({
      type: 'switch',
      setting: 'clarkwinkelmann-scout.queue',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.queue'),
      help: app.translator.trans('clarkwinkelmann-scout.admin.setting.queueHelp'),
    })
    .registerSetting({
      type: 'number',
      setting: 'clarkwinkelmann-scout.limit',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.limit'),
      placeholder: '200',
      help: app.translator.trans('clarkwinkelmann-scout.admin.setting.limitHelp'),
    })
    .registerSetting({
      type: 'number',
      setting: 'clarkwinkelmann-scout.queryMinLength',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.queryMinLength'),
      placeholder: '3',
      help: app.translator.trans('clarkwinkelmann-scout.admin.setting.queryMinLengthHelp'),
    })
    // Elasticsearch Settings
    .registerSetting({
      type: 'text',
      setting: 'clarkwinkelmann-scout.elasticsearchHost',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchHost'),
      placeholder: 'localhost:9200',
    })
    .registerSetting({
      type: 'select',
      setting: 'clarkwinkelmann-scout.elasticsearchAuthType',
      options: {
        none: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchAuthTypeNone'),
        basic: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchAuthTypeBasic'),
        apikey: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchAuthTypeApikey'),
      },
      default: 'none',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchAuthType'),
    })
    .registerSetting({
      type: 'text',
      setting: 'clarkwinkelmann-scout.elasticsearchUsername',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchUsername'),
    })
    .registerSetting({
      type: 'password',
      setting: 'clarkwinkelmann-scout.elasticsearchPassword',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchPassword'),
    })
    .registerSetting({
      type: 'password',
      setting: 'clarkwinkelmann-scout.elasticsearchApiKey',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchApiKey'),
    })
    .registerSetting({
      type: 'switch',
      setting: 'clarkwinkelmann-scout.elasticsearchSslVerification',
      label: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchSslVerification'),
      help: app.translator.trans('clarkwinkelmann-scout.admin.setting.elasticsearchSslVerificationHelp'),
    });
});
