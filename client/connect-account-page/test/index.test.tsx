/** @format */
/**
 * External dependencies
 */
import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import ConnectAccountPage from '..';

declare const global: {
	wcpaySettings: {
		connectUrl: string;
		connect: {
			country: string;
			availableCountries: Record< string, string >;
		};
		onBoardingDisabled?: boolean;
	};
};

describe( 'ConnectAccountPage', () => {
	beforeEach( () => {
		Object.defineProperty( window, 'location', {
			value: {
				assign: jest.fn(),
			},
		} );

		global.wcpaySettings = {
			connectUrl: '/wcpay-connect-url',
			connect: {
				country: 'US',
				availableCountries: { US: 'United States (US)' },
			},
		};
	} );

	test( 'should render correctly', () => {
		const { container: page } = render( <ConnectAccountPage /> );
		expect( page ).toMatchSnapshot();
	} );

	test( 'should render correctly when on-boarding disabled', () => {
		global.wcpaySettings.onBoardingDisabled = true;
		const { container: page } = render( <ConnectAccountPage /> );
		expect( page ).toMatchSnapshot();
	} );

	test( 'should prompt unsupported countries', () => {
		global.wcpaySettings = {
			connectUrl: '/wcpay-connect-url',
			connect: {
				country: 'CA',
				availableCountries: {
					GB: 'United Kingdom (UK)',
					US: 'United States (US)',
				},
			},
		};

		render( <ConnectAccountPage /> );
		userEvent.click( screen.getByRole( 'link', { name: /Finish setup/ } ) );

		const modalSelector =
			'.woocommerce-payments__onboarding_location_check-modal';
		expect( document.body.querySelector( modalSelector ) ).not.toBeNull();
	} );
} );
