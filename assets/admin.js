( function () {
    var data     = window.makaseteAdmin || {};
    var endpoint = data.endpoint;
    var nonce    = data.nonce;
    var labels   = data.labels || {};
    var pill     = document.getElementById( 'makasete-status-indicator' );
    var output   = document.getElementById( 'makasete-status-output' );

    if ( ! pill || ! output || ! endpoint ) {
        return;
    }

    var setPill = function ( cls, text ) {
        pill.className   = 'makasete-pill ' + cls;
        pill.textContent = text;
    };

    fetch( endpoint, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': nonce, 'Accept': 'application/json' }
    } ).then( function ( r ) {
        return r.json().then( function ( body ) {
            return { ok: r.ok, status: r.status, body: body };
        } );
    } ).then( function ( res ) {
        if ( res.ok && res.body && res.body.status === 'ok' ) {
            setPill( 'makasete-pill--ok', labels.connected || 'Connected' );
        } else {
            setPill( 'makasete-pill--error', labels.error || 'Error' );
        }
        output.textContent = JSON.stringify( res.body, null, 2 );
    } ).catch( function ( err ) {
        setPill( 'makasete-pill--error', labels.unreachable || 'Unreachable' );
        output.textContent = String( err );
    } );
} )();
