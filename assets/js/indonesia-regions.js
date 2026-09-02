( function () {
	'use strict';

	if ( typeof lwcIndonesiaRegions === 'undefined' ) {
		return;
	}

	const config = lwcIndonesiaRegions;
	const cache = new Map();
	const cityRuns = { shipping: 0, billing: 0 };
	const districtRuns = { shipping: 0, billing: 0 };
	let scheduled = false;

	function field( group, key ) {
		const selectors = [
			`#${ group }-${ key }`,
			`#${ group }_${ key }`,
			`[name="${ group }_${ key }"]`,
			`[id^="${ group }-${ key }-"]`,
		];
		return document.querySelector( selectors.join( ',' ) );
	}

	function districtField( group ) {
		const candidates = Array.from( document.querySelectorAll( '[data-lwc-indonesia-district="1"], .lwc-indonesia-district-source, [name$="_lwc_indonesia_district"], [id$="_lwc_indonesia_district"]' ) );
		return candidates.find( ( element ) => {
			const identity = `${ element.id || '' } ${ element.name || '' }`.toLowerCase();
			return identity.includes( group );
		} ) || null;
	}

	function setRegionCookie( group, state, city, district ) {
		const name = `lwc_${ group }_region`;
		const secure = window.location.protocol === 'https:' ? '; Secure' : '';
		if ( ! state || ! city || ! district ) {
			document.cookie = `${ name }=; Max-Age=0; Path=/; SameSite=Lax${ secure }`;
			return;
		}
		const value = encodeURIComponent( JSON.stringify( { state, city, district } ) );
		document.cookie = `${ name }=${ value }; Max-Age=7200; Path=/; SameSite=Lax${ secure }`;
	}

	function triggerShippingRefresh( group ) {
		const postcode = field( group, 'postcode' ) || field( group === 'shipping' ? 'billing' : 'shipping', 'postcode' );
		if ( postcode ) {
			postcode.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			postcode.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( window.jQuery && document.body ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
		}
	}

	function configurePostcode( group, isIndonesia ) {
		const postcode = field( group, 'postcode' );
		if ( ! postcode ) {
			return;
		}
		if ( typeof postcode.dataset.lwcOriginalRequired === 'undefined' ) {
			postcode.dataset.lwcOriginalRequired = postcode.required ? '1' : '0';
			postcode.dataset.lwcOriginalPattern = postcode.getAttribute( 'pattern' ) || '';
			postcode.dataset.lwcOriginalMaxlength = postcode.getAttribute( 'maxlength' ) || '';
			postcode.dataset.lwcOriginalInputmode = postcode.getAttribute( 'inputmode' ) || '';
		}
		postcode.required = isIndonesia || postcode.dataset.lwcOriginalRequired === '1';
		if ( isIndonesia ) {
			postcode.setAttribute( 'inputmode', 'numeric' );
			postcode.setAttribute( 'pattern', '[0-9]{5}' );
			postcode.setAttribute( 'maxlength', '5' );
		} else {
			postcode.dataset.lwcOriginalPattern ? postcode.setAttribute( 'pattern', postcode.dataset.lwcOriginalPattern ) : postcode.removeAttribute( 'pattern' );
			postcode.dataset.lwcOriginalMaxlength ? postcode.setAttribute( 'maxlength', postcode.dataset.lwcOriginalMaxlength ) : postcode.removeAttribute( 'maxlength' );
			postcode.dataset.lwcOriginalInputmode ? postcode.setAttribute( 'inputmode', postcode.dataset.lwcOriginalInputmode ) : postcode.removeAttribute( 'inputmode' );
		}
	}

	function fieldContainer( source ) {
		return source && ( source.closest( 'p.form-row' ) || source.closest( '.wc-block-components-text-input' ) || source.parentElement );
	}

	function setSourceValue( source, value ) {
		if ( ! source || source.value === value ) {
			return;
		}
		const prototype = source.tagName === 'SELECT' ? window.HTMLSelectElement.prototype : window.HTMLInputElement.prototype;
		const descriptor = Object.getOwnPropertyDescriptor( prototype, 'value' );
		if ( descriptor && descriptor.set ) {
			descriptor.set.call( source, value );
		} else {
			source.value = value;
		}
		source.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		source.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function removeEnhancement( source, hideField ) {
		if ( ! source ) {
			return;
		}
		const container = fieldContainer( source );
		const select = container && container.querySelector( ':scope > .lwc-region-select, .lwc-region-select' );
		if ( select ) {
			select.remove();
		}
		if ( container ) {
			container.querySelectorAll( '.lwc-region-review' ).forEach( ( notice ) => notice.remove() );
			container.classList.remove( 'lwc-region-control' );
			const label = container.querySelector( '.lwc-region-label' );
			if ( label ) {
				if ( label.dataset.lwcCreatedLabel === '1' ) {
					label.remove();
				} else {
					label.innerHTML = label.dataset.lwcOriginalHtml || label.textContent;
					label.setAttribute( 'for', label.dataset.lwcOriginalFor || source.id || '' );
					label.classList.remove( 'lwc-region-label' );
				}
			}
		}
		source.classList.remove( 'lwc-region-source' );
		if ( container ) {
			container.classList.toggle( 'lwc-region-hidden', Boolean( hideField ) );
		}
	}

	function ensureSelect( source, kind, label ) {
		const container = fieldContainer( source );
		if ( ! container ) {
			return null;
		}
		container.classList.remove( 'lwc-region-hidden' );
		container.classList.add( 'lwc-region-control' );
		source.classList.add( 'lwc-region-source' );

		let select = container.querySelector( `.lwc-region-select[data-lwc-region-kind="${ kind }"]` );
		if ( ! select ) {
			select = document.createElement( 'select' );
			select.className = 'lwc-region-select';
			select.dataset.lwcRegionKind = kind;
			select.autocomplete = kind === 'city' ? 'address-level2' : 'address-level3';
			source.insertAdjacentElement( 'afterend', select );
		}
		select.id = `${ source.id || kind }-lwc-region-select`;
		select.setAttribute( 'aria-label', label );

		let regionLabel = container.querySelector( '.lwc-region-label' );
		if ( ! regionLabel ) {
			regionLabel = container.querySelector( 'label' );
			if ( regionLabel ) {
				regionLabel.dataset.lwcOriginalHtml = regionLabel.innerHTML;
				regionLabel.dataset.lwcOriginalFor = regionLabel.getAttribute( 'for' ) || '';
			} else {
				regionLabel = document.createElement( 'label' );
				regionLabel.dataset.lwcCreatedLabel = '1';
				select.insertAdjacentElement( 'beforebegin', regionLabel );
			}
			regionLabel.classList.add( 'lwc-region-label' );
		}
		if ( regionLabel.textContent !== label ) {
			regionLabel.textContent = label;
		}
		regionLabel.setAttribute( 'for', select.id );
		return select;
	}

	function option( value, label ) {
		const item = document.createElement( 'option' );
		item.value = value;
		item.textContent = label;
		return item;
	}

	function normalizeName( value ) {
		return String( value || '' )
			.normalize( 'NFD' )
			.replace( /[\u0300-\u036f]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, ' ' )
			.trim();
	}

	function withoutCityPrefix( value ) {
		return normalizeName( value ).replace( /^(kabupaten|kab|kota)\s+/, '' );
	}

	function matchStoredName( rows, key, value, allowCityPrefix ) {
		const normalized = normalizeName( value );
		if ( ! normalized ) {
			return '';
		}
		const exact = rows.filter( ( row ) => normalizeName( row[ key ] ) === normalized );
		if ( ! allowCityPrefix ) {
			return exact.length === 1 ? exact[ 0 ][ key ] : '';
		}
		if ( exact.length === 1 ) {
			return exact[ 0 ][ key ];
		}

		const looseValue = withoutCityPrefix( value );
		const loose = rows.filter( ( row ) => withoutCityPrefix( row[ key ] ) === looseValue );
		return loose.length === 1 ? loose[ 0 ][ key ] : '';
	}

	function rememberedValue( source, reset ) {
		if ( source.value ) {
			source.dataset.lwcStoredRegion = source.value;
		}
		return reset ? '' : source.dataset.lwcStoredRegion || '';
	}

	function clearRememberedValue( source ) {
		if ( ! source ) {
			return;
		}
		delete source.dataset.lwcStoredRegion;
		setSourceValue( source, '' );
	}

	function setReviewNotice( select, message ) {
		const container = fieldContainer( select );
		if ( ! container ) {
			return;
		}
		let notice = container.querySelector( '.lwc-region-review' );
		if ( ! message ) {
			select.setCustomValidity( '' );
			if ( notice ) {
				notice.remove();
			}
			return;
		}
		select.setCustomValidity( message );
		if ( ! notice ) {
			notice = document.createElement( 'span' );
			notice.className = 'lwc-region-review';
			notice.setAttribute( 'role', 'alert' );
			select.insertAdjacentElement( 'afterend', notice );
		}
		notice.textContent = message;
	}

	async function getJson( url ) {
		if ( ! cache.has( url ) ) {
			cache.set( url, fetch( url, { credentials: 'same-origin', headers: { Accept: 'application/json' } } ).then( ( response ) => {
				if ( ! response.ok ) {
					throw new Error( config.loadError );
				}
				return response.json();
			} ) );
		}
		return cache.get( url );
	}

	async function populateDistricts( group, stateCode, cityName, reset ) {
		const run = ++districtRuns[ group ];
		const country = field( group, 'country' );
		const source = districtField( group );
		if ( ! source ) {
			return;
		}
		if ( ! country || country.value !== 'ID' ) {
			setRegionCookie( group, '', '', '' );
			removeEnhancement( source, true );
			return;
		}

		const select = ensureSelect( source, 'district', config.districtLabel );
		if ( ! select ) {
			return;
		}
		const previous = rememberedValue( source, reset );
		select.replaceChildren( option( '', cityName ? config.districtChoose : config.districtFirst ) );
		select.disabled = ! cityName;
		select.required = true;
		if ( ! stateCode || ! cityName ) {
			setSourceValue( source, '' );
			return;
		}

		try {
			const rows = await getJson( `${ config.districtsUrl }?state=${ encodeURIComponent( stateCode ) }&city=${ encodeURIComponent( cityName ) }` );
			if ( run !== districtRuns[ group ] ) {
				return;
			}
			rows.forEach( ( row ) => select.appendChild( option( row.district_name, row.district_name ) ) );
			select.disabled = false;
			select.value = matchStoredName( rows, 'district_name', previous, false );
			setReviewNotice( select, previous && ! select.value ? config.districtReview : '' );
			setSourceValue( source, select.value );
			setRegionCookie( group, stateCode, cityName, select.value );
			select.onchange = () => {
				source.dataset.lwcStoredRegion = select.value;
				setReviewNotice( select, '' );
				setSourceValue( source, select.value );
				setRegionCookie( group, stateCode, cityName, select.value );
				triggerShippingRefresh( group );
			};
		} catch ( error ) {
			if ( run !== districtRuns[ group ] ) {
				return;
			}
			select.replaceChildren( option( '', config.loadError ) );
			select.disabled = true;
		}
	}

	async function enhanceGroup( group, reset ) {
		const run = ++cityRuns[ group ];
		districtRuns[ group ]++;
		const country = field( group, 'country' );
		const state = field( group, 'state' );
		const citySource = field( group, 'city' );
		const districtSource = districtField( group );
		if ( ! country || ! citySource ) {
			return;
		}
		configurePostcode( group, country.value === 'ID' );

		if ( country.value !== 'ID' ) {
			setRegionCookie( group, '', '', '' );
			removeEnhancement( citySource, false );
			removeEnhancement( districtSource, true );
			return;
		}

		if ( districtSource ) {
			const districtContainer = fieldContainer( districtSource );
			if ( districtContainer ) {
				districtContainer.classList.remove( 'lwc-region-hidden' );
			}
		}

		const select = ensureSelect( citySource, 'city', config.cityLabel );
		if ( ! select ) {
			return;
		}
		const stateCode = state ? state.value : '';
		const previous = rememberedValue( citySource, reset );
		select.replaceChildren( option( '', stateCode ? config.cityPlaceholder : config.stateFirst ) );
		select.disabled = ! stateCode;
		select.required = true;

		if ( ! stateCode ) {
			setSourceValue( citySource, '' );
			await populateDistricts( group, '', '', true );
			return;
		}

		try {
			const rows = await getJson( `${ config.citiesUrl }?state=${ encodeURIComponent( stateCode ) }` );
			if ( run !== cityRuns[ group ] ) {
				return;
			}
			rows.forEach( ( row ) => select.appendChild( option( row.city_name, row.city_name ) ) );
			select.disabled = false;
			select.value = matchStoredName( rows, 'city_name', previous, true );
			setReviewNotice( select, previous && ! select.value ? config.cityReview : '' );
			setSourceValue( citySource, select.value );

			await populateDistricts( group, stateCode, select.value, reset || ! select.value );
			select.onchange = async () => {
				citySource.dataset.lwcStoredRegion = select.value;
				setReviewNotice( select, '' );
				setSourceValue( citySource, select.value );
				clearRememberedValue( districtSource );
				setRegionCookie( group, '', '', '' );
				await populateDistricts( group, stateCode, select.value, true );
			};
		} catch ( error ) {
			if ( run !== cityRuns[ group ] ) {
				return;
			}
			select.replaceChildren( option( '', config.loadError ) );
			select.disabled = true;
			await populateDistricts( group, '', '', true );
		}
	}

	function enhanceAll( reset ) {
		Promise.all( [ enhanceGroup( 'shipping', reset ), enhanceGroup( 'billing', reset ) ] ).catch( () => {} );
	}

	function scheduleEnhancement() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.setTimeout( () => {
			scheduled = false;
			enhanceAll( false );
		}, 80 );
	}

	function isInternalMutation( mutation ) {
		const target = mutation.target.nodeType === 1 ? mutation.target : mutation.target.parentElement;
		if ( target && target.closest( '.lwc-region-select, .lwc-region-label, .lwc-region-review' ) ) {
			return true;
		}
		const changedNodes = [ ...mutation.addedNodes, ...mutation.removedNodes ].filter( ( node ) => node.nodeType === 1 );
		return changedNodes.length > 0 && changedNodes.every( ( node ) => node.matches( '.lwc-region-select, .lwc-region-label, .lwc-region-review, option' ) );
	}

	document.addEventListener( 'change', ( event ) => {
		const target = event.target;
		if ( ! target || target.classList.contains( 'lwc-region-select' ) ) {
			return;
		}
		const identity = `${ target.id || '' } ${ target.name || '' }`.toLowerCase();
		if ( identity.includes( 'country' ) || identity.includes( 'state' ) ) {
			const group = identity.includes( 'shipping' ) ? 'shipping' : identity.includes( 'billing' ) ? 'billing' : '';
			if ( event.isTrusted && group ) {
				clearRememberedValue( field( group, 'city' ) );
				clearRememberedValue( districtField( group ) );
			}
			window.setTimeout( () => group ? enhanceGroup( group, true ) : enhanceAll( true ), 0 );
		}
	} );

	new MutationObserver( ( mutations ) => {
		if ( mutations.some( ( mutation ) => ! isInternalMutation( mutation ) ) ) {
			scheduleEnhancement();
		}
	} ).observe( document.body, { childList: true, subtree: true } );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => enhanceAll( false ) );
	} else {
		enhanceAll( false );
	}
}() );
