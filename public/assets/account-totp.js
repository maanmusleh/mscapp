(() => {
  'use strict';

  const target = document.querySelector('[data-totp-qr]');
  const provisioningUri = target?.dataset.totpUri;
  if (!target || !provisioningUri || typeof QRCode === 'undefined') return;

  new QRCode(target, {
    text: provisioningUri,
    width: 180,
    height: 180,
    colorDark: '#071a3a',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M,
  });
})();
