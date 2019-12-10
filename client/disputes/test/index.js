/** @format */
/**
 * External dependencies
 */
import { shallow } from 'enzyme';

/**
 * Internal dependencies
 */
import { DisputesList } from '../';

describe( 'Disputes list', () => {
	test( 'renders correctly', () => {
		const disputes = {
			data: [
				{
					id: 'dp_asdfghjkl',
                    amount: 1000,
                    created: 1572590800,
                    // eslint-disable-next-line camelcase
                    evidence_details: {
                        // eslint-disable-next-line camelcase
                        due_by: 1573199200,
                    },
                    reason: 'fraudulent',
                    status: 'needs_response',
				},
				{
					id: 'dp_zxcvbnm',
                    amount: 1050,
                    created: 1572480800,
                    // eslint-disable-next-line camelcase
                    evidence_details: {
                        // eslint-disable-next-line camelcase
                        due_by: 1573099200,
                    },
                    reason: 'general',
                    status: 'under_review',
				},
			],
		};

		const list = shallow(
			<DisputesList
				disputes={ disputes }
				showPlaceholder={ false }
			/>
		);
		expect( list ).toMatchSnapshot();
	} );
} );
