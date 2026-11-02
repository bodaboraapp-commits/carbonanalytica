
  /* Guarded: if this script runs before the form exists, the mailto fallback still works. */
  var proposalForm = document.getElementById('proposalForm');
  if (proposalForm) proposalForm.addEventListener('submit', function(e){
    e.preventDefault();
    var name = document.getElementById('name').value;
    var org = document.getElementById('org').value;
    var email = document.getElementById('email').value;
    var service = document.getElementById('service').value;
    var message = document.getElementById('message').value;
    var subject = encodeURIComponent('Proposal request — ' + (org || name));
    var body = encodeURIComponent(
      'Name: ' + name + '\n' +
      'Organization: ' + org + '\n' +
      'Email: ' + email + '\n' +
      'Service of interest: ' + service + '\n\n' +
      'Project details:\n' + message
    );
    window.location.href = 'mailto:hello@carbonanalytica.com?subject=' + subject + '&body=' + body;
  });

  document.querySelectorAll('section .section-head, .service-card, .proof-card, .impact-card, .logi-card, .timeline .tl-item, .metric-band')
    .forEach(function(el){ el.classList.add('reveal'); });

  if ('IntersectionObserver' in window) {
    var observer = new IntersectionObserver(function(entries){
      entries.forEach(function(entry){
        if(entry.isIntersecting){
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, {threshold:0.08});
    document.querySelectorAll('.reveal').forEach(function(el){ observer.observe(el); });
  } else {
    document.querySelectorAll('.reveal').forEach(function(el){ el.classList.add('is-visible'); });
  }

  /* Local-market currency conversion.
     Rates are indicative reference rates and should be refreshed before formal quotations. */
  (function(){
    var currencyData = {
      KE:{name:'Kenya', code:'KES', symbol:'KES', rate:129.62},
      TZ:{name:'Tanzania', code:'TZS', symbol:'TZS', rate:2500},
      UG:{name:'Uganda', code:'UGX', symbol:'UGX', rate:3500},
      RW:{name:'Rwanda', code:'RWF', symbol:'RWF', rate:1450},
      GH:{name:'Ghana', code:'GHS', symbol:'GHS', rate:12.5},
      NG:{name:'Nigeria', code:'NGN', symbol:'₦', rate:1500},
      ZA:{name:'South Africa', code:'ZAR', symbol:'R', rate:17.2},
      ZM:{name:'Zambia', code:'ZMW', symbol:'ZMW', rate:24},
      MW:{name:'Malawi', code:'MWK', symbol:'MWK', rate:1750},
      ET:{name:'Ethiopia', code:'ETB', symbol:'ETB', rate:145},
      MZ:{name:'Mozambique', code:'MZN', symbol:'MZN', rate:64},
      US:{name:'United States', code:'USD', symbol:'$', rate:1}
    };

    var selector = document.getElementById('countrySelector');
    var header = document.getElementById('localCurrencyHeader');
    var rateDisplay = document.getElementById('rateDisplay');

    function money(value, code){
      if(code === 'NGN') return '₦' + Math.round(value).toLocaleString('en-US');
      if(code === 'ZAR') return 'R' + Math.round(value).toLocaleString('en-US');
      if(code === 'USD') return '$' + value.toFixed(2);
      return code + ' ' + Math.round(value).toLocaleString('en-US');
    }

    function updatePricing(){
      var market = currencyData[selector.value] || currencyData.KE;
      header.textContent = market.name + ' — ' + market.code;
      rateDisplay.textContent = 'Indicative rate: 1 USD = ' + market.rate.toLocaleString('en-US', {
        maximumFractionDigits: 2
      }) + ' ' + market.code;

      document.querySelectorAll('#pricingTable tbody tr').forEach(function(row){
        if(!row.hasAttribute('data-min')) return; /* "On request" rows have no fixed range */
        var min = parseFloat(row.getAttribute('data-min'));
        var max = parseFloat(row.getAttribute('data-max'));
        row.querySelector('.local-amt').textContent =
          money(min * market.rate, market.code) + '–' + money(max * market.rate, market.code);
      });
    }

    if(selector){
      selector.addEventListener('change', updatePricing);
      updatePricing();
    }
  })();

  /* "Our Tech" dropdown: click/tap to open; click outside or press Escape to close. */
  (function(){
    var drop = document.getElementById('techDrop');
    if (!drop) return;
    var btn = document.getElementById('techDropBtn');
    var menu = document.getElementById('techDropMenu');

    function setOpen(open){
      if (open) { drop.classList.add('is-open'); } else { drop.classList.remove('is-open'); }
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    btn.addEventListener('click', function(e){
      e.preventDefault();
      setOpen(!drop.classList.contains('is-open'));
    });

    document.addEventListener('click', function(e){
      if (!drop.contains(e.target)) setOpen(false);
    });

    document.addEventListener('keydown', function(e){
      if ((e.key === 'Escape' || e.key === 'Esc') && drop.classList.contains('is-open')) {
        setOpen(false);
        btn.focus();
      }
    });

    menu.addEventListener('click', function(e){
      if (e.target.closest('a')) setOpen(false);
    });
  })();
